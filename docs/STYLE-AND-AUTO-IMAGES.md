# Học văn phong & tự thêm ảnh cho bài (v1.0.5, ADR-029)

## 1. Học văn phong

**Mục tiêu:** AI viết bài mới theo đúng cách viết của website khách hàng.

**Luồng**
1. Customer › Viết bài › Giọng văn & preset › **Học văn phong** trên một preset.
2. Chọn 2–20 bài mẫu: bài đã đăng (từ chỉ mục đã đồng bộ của website) và/hoặc dán nội dung (≥ 300 ký tự, tối đa 10 bài dán).
3. `POST /api/v1/workspaces/{ws}/writer/presets/{preset}/learn-style` → 202 + `job_run` (queue `writing`, job `LearnWritingStyle`).
4. Queue: `StyleLearner::collect` tải từng bài qua `PublicWebFetcher` (SSRF guard, chỉ cùng host với website của preset, không theo redirect sang site khác), trích văn bản bằng `PageExtractor`; bài không đọc được ghi vào `style_sources.skipped`.
5. Một lời gọi AI, task `writer.style_profile` (route mặc định sao chép từ `writer.brief`; Super Admin đổi trong AI Settings). Mỗi bài mẫu gửi tối đa ~3.200 ký tự (đầu + cuối bài), tổng ≤ 40.000 ký tự, nằm trong `<data>` sau chỉ dẫn.
6. Kết quả lưu vào `writing_presets.style_draft` (chưa dùng cho bài viết). Người dùng xem, sửa và bấm **Lưu** → `style_profile` (áp dụng), `style_draft` = null. `DELETE …/style-draft` để bỏ bản nháp.

**Hồ sơ văn phong** (`style_profile`): `summary, tone, addressing, sentence_style, paragraph_style, intro_pattern, conclusion_pattern, heading_style, formatting` (chuỗi) và `vocabulary, signature_phrases, avoid, dos, donts` (danh sách).

**Áp dụng:** `ArticleContext::brandVoice()` thêm `style_profile`; prompt viết section yêu cầu theo văn phong này nhưng quy tắc bằng chứng luôn ưu tiên (văn phong không phải nguồn sự thật).

**Quy tắc**
- Chỉ bài của chính website hoặc người dùng tự dán; không có chức năng học từ URL bên ngoài/đối thủ.
- Không chép câu chữ: mọi câu/mục trong kết quả có chuỗi ≥ 8 từ liên tiếp trùng bài mẫu bị loại (`StyleLearner::sanitize`). Mỗi trường bị giới hạn độ dài/số mục.
- Không lưu nội dung bài mẫu; chỉ lưu tiêu đề, URL, số từ.
- Chi phí đi qua AI Gateway (ngân sách workspace/toàn hệ thống, ghi `ai_requests`). Không retry lời gọi tốn phí.
- Quyền: `operate` trên preset (viewer bị 403); route có `sitex.verified` + `throttle:writer-ai`.

### 1.1. Giao diện tối giản & văn phong dựng sẵn (v1.0.9)

- **Viết bài mới** (`/writer/new`) chỉ còn 3 câu hỏi: chủ đề, văn phong, độ dài (Ngắn 800 / Vừa 1.500 / Dài 2.500 chữ), 3 công tắc (ảnh minh họa, tìm trên Internet, link bài cũ) và phần **Tùy chỉnh thêm** đóng sẵn. Bỏ trống thì AI tự quyết. Chế độ duyệt mặc định đổi thành `final_only` ("Chỉ duyệt bài cuối"); API vẫn nhận đủ tham số cũ.
- **Văn phong** (`/writer/presets`): "Văn phong của bạn" (preset) và "Văn phong dựng sẵn". 7 kiểu dựng sẵn (`Writer/Support/BuiltinStyles`: friendly, expert, step_by_step, sales, storytelling, news, formal) là hồ sơ văn phong cùng dạng với hồ sơ đã học. Bài chọn kiểu dựng sẵn gửi `builtin_style` (lưu `settings.builtin_style`); `ArticleContext::brandVoice()` dùng hồ sơ của preset nếu preset đã có văn phong, nếu không thì dùng kiểu dựng sẵn. **Tạo bản riêng** = `POST presets` với `from_builtin` (sao chép hồ sơ, `style_sources.origin = {type: builtin}`).
- **Dạy AI văn phong mới** (3 bước): nguồn → chọn bài → xem kết quả & lưu. Nguồn: một bài, một danh mục, một tác giả, toàn bộ blog, sản phẩm, dán văn bản. Giao diện tự chọn bài (mới nhất trước) bằng `GET sites/{site}/content?filter[term]=|filter[author]=|filter[status]=publish`, người dùng bỏ chọn được; vẫn gửi `content_item_ids` như cũ kèm `origin {type, label}` (lưu `style_sources.origin`, hiển thị trên thẻ). Tối thiểu **1** bài (`StyleLearner::MIN_SAMPLES`), tối đa 20; sản phẩm được tính là bài của website.
- **Tác giả:** plugin 1.2.0 gửi `author {id, name}` (chỉ tên hiển thị công khai) khi đồng bộ; Cloud lưu `site_content_items.author_wp_id/author_name` và có `GET sites/{site}/authors`. Website dùng plugin cũ: danh sách tác giả trống và giao diện nhắc cập nhật plugin rồi đồng bộ lại.
- Công tắc ảnh theo từng bài: `auto_images` (bool) trong `settings` thắng mặc định của preset/hệ thống (`DraftWorkflow::imageTarget`).

## 2. Tự thêm ảnh (tối thiểu N ảnh mỗi bài)

**Lựa chọn của chủ dự án:** ưu tiên ảnh thư viện, thiếu thì tạo ảnh AI.

**Luồng**
1. `DraftWorkflow::finalize` phát event `Writer\Events\ArticleDraftReady(workspaceId, articleId, versionId, minImages)`.
   `minImages` = preset (`defaults.auto_images`, `defaults.min_images`) hoặc mặc định hệ thống `SITEX_MEDIA_AUTO_IMAGES` (true) / `SITEX_MEDIA_MIN_ARTICLE_IMAGES` (2). 0 = tắt.
2. Media lắng nghe → job `AutoIllustrateArticle` (queue `media`) → `Media\Actions\AutoIllustrate::run`:
   - chạy Placement Engine (ảnh đại diện + mục cần ảnh);
   - thêm vị trí ở các mục dài nhất (bỏ FAQ/kết luận/nguồn) cho đủ N;
   - nhận ảnh thư viện mà engine khớp: ảnh sẵn sàng, rõ bản quyền; ảnh AI chỉ khi được tạo cho chính bài này;
   - với vị trí còn trống: gọi `GenerateImages::create` (kiểm tra model ảnh, giới hạn ảnh AI/ngày, trần chi phí mỗi job, ngân sách AI) cho tới khi đủ N; ảnh đại diện luôn được lấp. Khóa idempotency `auto:{article}:{placement}:{version}` → chạy lại không trả tiền hai lần. Kiểu ảnh mặc định `SITEX_MEDIA_AUTO_IMAGE_STYLE` (`editorial_concept`).
3. Khi job ảnh xong (`RunImageGeneration`), `AutoIllustrate::finished` đặt ảnh vào đúng vị trí (nếu người dùng chưa đụng vào vị trí đó), alt text gợi ý, chú thích "Ảnh minh họa tạo bằng AI".
4. Trạng thái lưu ở `media_autofill_runs` (một dòng/bài): `running | completed | incomplete | failed`, số ảnh thư viện/AI, lý do thiếu. Trang **Ảnh trong bài viết** hiển thị trạng thái này.

**Quy tắc**
- Không thay đổi vị trí người dùng đã tạo/sửa (`origin = user` hoặc không còn `suggested`).
- Ảnh AI luôn gắn nhãn, không bao giờ dùng cho vị trí ảnh sản phẩm.
- Không có model ảnh / hết hạn mức → bài vẫn hoàn tất nhưng `incomplete` + lý do; không chặn bài.
- SEO Audit cảnh báo (WARNING) khi bài có ít hơn `min_article_images` ảnh (kể cả ảnh đại diện). Cổng đăng bài không đổi.

**Giới hạn đã biết**
- Ảnh AI tạo bất đồng bộ: nếu Auto-approve + auto publish bật, bài có thể được duyệt trước khi ảnh AI xong (ảnh đang tạo chưa ở trạng thái `accepted`, nên không bị đăng dở).
- Chưa thử với model ảnh thật trên staging (cần cấu hình route `media.image`).

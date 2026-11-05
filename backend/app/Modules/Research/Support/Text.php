<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Support;

/**
 * Language-aware text helpers for Vietnamese and English keyword work.
 *
 * - normalize(): lowercase, punctuation stripped, keeps diacritics (display form)
 * - fold(): normalize + diacritics removed (matching form; "Wi-Fi" → "wifi")
 * - tokens(): folded words without function words
 * - topicTokens(): tokens without intent modifiers ("cách", "giá", "so sánh"...)
 *   — the subject of the query, used for topic similarity.
 */
final class Text
{
    /** Vietnamese function words (folded). */
    private const STOP = [
        'la', 'va', 'cua', 'cho', 'voi', 'cac', 'nhung', 'mot', 'trong', 'tren', 'duoi', 'khi', 'thi', 'ma', 'nay', 'do',
        'de', 'duoc', 'bi', 'co', 'khong', 'cung', 'nhu', 'hay', 'hoac', 'tu', 'den', 've', 'ra', 'vao', 'len', 'xuong',
        'toi', 'chung', 'ta', 'minh', 'se', 'da', 'dang', 'rat', 'qua', 'nua', 'theo', 'sau', 'truoc', 'neu', 'vi', 'boi',
        'o', 'nhieu', 'it', 'moi', 'tat', 'ca', 'cai', 'chiec', 'con', 'nguoi', 'viec', 'nhat', 'hon', 'thuoc', 'tung', 'luc',
        'phai', 'can', 'muon', 'nen', 'roi', 'ay', 'kia', 'day', 'ai', 'gi', 'nao', 'sao', 'bao', 'dau',
    ];

    /**
     * English function words, matched on the unfolded word so Vietnamese
     * words that fold to the same letters ("ồn" → "on", "tô" → "to") stay.
     */
    private const STOP_EN = [
        'a', 'an', 'of', 'for', 'to', 'in', 'on', 'and', 'or', 'with', 'is', 'are', 'be', 'by', 'at', 'from', 'it', 'this',
        'that', 'your', 'you', 'my', 'our', 'we', 'i', 'as', 'do', 'does', 'can', 'will', 'into', 'about', 'which', 'who', 'what',
        'why', 'when', 'where', 'how',
    ];

    /** Intent / format modifiers (folded) — not part of the topic. */
    private const MODIFIERS = [
        'cach', 'huong', 'dan', 'meo', 'tot', 'gia', 're', 'mua', 'so', 'sanh', 'vs', 'review', 'danh', 'top', 'loi', 'sua',
        'khac', 'phuc', 'moi', 'nhat', 'chinh', 'hang', 'uy', 'tin', 'bang', 'chi', 'tiet', 'don', 'gian', 'nhanh',
        'hieu', 'qua', 'thong', 'tin', 'kinh', 'nghiem', 'lua', 'chon', 'nen', 'mua', 'loai', 'tim', 'hieu', 'tong', 'hop', 'kiem',
        'tra', 'khuyen', 'mai', 'giam', 'online', 'ship', 'dat', 'thay', 'nang', 'cap',
        'best', 'how', 'guide', 'tutorial', 'price', 'buy', 'cheap', 'review', 'reviews', 'compare', 'comparison', 'versus',
        'fix', 'error', 'top', 'new', 'latest', 'tips', 'vs', 'deal', 'deals', 'sale',
    ];

    /** @var array<string, true>|null */
    private static ?array $stop = null;

    /** @var array<string, true>|null */
    private static ?array $stopEn = null;

    /**
     * @param  string  $word  normalized (diacritics kept)
     * @param  string  $folded  fold($word)
     */
    private static function isStop(string $word, string $folded): bool
    {
        self::$stop ??= array_fill_keys(self::STOP, true);
        self::$stopEn ??= array_fill_keys(self::STOP_EN, true);

        return isset(self::$stop[$folded]) || isset(self::$stopEn[$word]);
    }

    /** @var array<string, true>|null */
    private static ?array $modifiers = null;

    public static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        // "wi-fi" → "wifi", "e-mail" → "email" (hyphen between letters)
        $text = (string) preg_replace('/(?<=\p{L})-(?=\p{L})/u', '', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function fold(string $text): string
    {
        $text = self::normalize($text);
        $text = str_replace(['đ', 'Đ'], 'd', $text);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        return trim((string) preg_replace('/[^a-z0-9\s]+/', '', $text));
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $out = [];
        foreach (explode(' ', self::normalize($text)) as $word) {
            $w = self::fold($word);
            if ($w === '' || self::isStop($word, $w) || (mb_strlen($w) < 2 && ! ctype_digit($w))) {
                continue;
            }
            $out[] = $w;
        }

        return $out;
    }

    /**
     * Subject tokens: tokens without intent/format modifiers. Falls back to
     * tokens() when the query is only modifiers ("giá rẻ").
     *
     * @return list<string>
     */
    public static function topicTokens(string $text): array
    {
        $mods = self::$modifiers ??= array_fill_keys(self::MODIFIERS, true);
        $tokens = self::tokens($text);
        $topic = array_values(array_filter($tokens, static fn (string $t): bool => ! isset($mods[$t])));

        return $topic === [] ? $tokens : array_values(array_unique($topic));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function jaccard(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $inter / $union;
    }

    /**
     * Share of $needle tokens present in $haystack (0..1).
     *
     * @param  list<string>  $needle
     * @param  list<string>  $haystack
     */
    public static function containment(array $needle, array $haystack): float
    {
        $needle = array_unique($needle);
        if ($needle === []) {
            return 0.0;
        }

        return count(array_intersect($needle, array_unique($haystack))) / count($needle);
    }

    /**
     * Topic similarity: max of jaccard and the containment of the shorter set
     * in the longer one (so "card wifi" ≈ "card wifi intel ax210").
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        [$short, $long] = count(array_unique($a)) <= count(array_unique($b)) ? [$a, $b] : [$b, $a];
        $containment = self::containment($short, $long);
        // Containment of a 1-word set is too weak a signal on its own.
        if (count(array_unique($short)) < 2) {
            $containment *= 0.6;
        }

        return max(self::jaccard($a, $b), $containment * 0.9);
    }

    public static function fingerprint(string ...$parts): string
    {
        return sha1(implode('|', $parts));
    }

    public static function keywordFingerprint(string $keyword): string
    {
        return sha1(self::fold($keyword));
    }

    /**
     * Common Vietnamese two-syllable words (folded) that a phrase must never
     * be cut through. Domain compounds are added at runtime from the
     * campaign's own vocabulary (categories, products, audiences...).
     */
    public const COMPOUNDS = [
        'tai nghe', 'ban phim', 'dien thoai', 'may tinh', 'cong nghe', 'phu kien', 'bao hanh', 'sinh vien', 'hoc sinh',
        've sinh', 'dung cach', 'chong on', 'lap trinh', 'van phong', 'gia dinh', 'tre em', 'nguoi moi', 'bat dau',
        'suc khoe', 'kinh nghiem', 'huong dan', 'so sanh', 'gia re', 'chinh hang', 'hieu qua', 'tot nhat', 'danh gia',
        'lua chon', 'khac phuc', 'nguyen nhan', 'san pham', 'dich vu', 'cua hang', 'khach hang', 'thuong hieu',
        'chat luong', 'man hinh', 'am thanh', 'bo nho', 'hieu nang', 'thoi luong', 'ket noi', 'phan mem', 'phan cung',
        'an toan', 'bao mat', 'tiet kiem', 'gia tot', 'mua sam', 'khuyen mai', 'giam gia', 'thanh toan', 'van chuyen',
        'giao hang', 'lam dep', 'my pham', 'thoi trang', 'du lich', 'nha hang', 'khach san', 'bat dong', 'dong san',
        'tai chinh', 'ngan hang', 'bao hiem', 'giao duc', 'tieng anh', 'noi that', 'xay dung', 'o to', 'xe may',
        'thu cung', 'thuc pham', 'an uong', 'dinh duong', 'giam can', 'tap luyen', 'the thao', 'cham soc', 'lam viec',
        'hoc tap', 'choi game', 'tro choi', 'nhiep anh', 'may anh', 'dong ho', 'pin sac', 'sac du', 'cau hinh',
    ];

    /** @var array<string, true>|null */
    private static ?array $compounds = null;

    /**
     * Folded two-syllable compounds found inside the given phrases.
     *
     * @param  iterable<string>  $phrases
     * @return array<string, true>
     */
    public static function compoundsFrom(iterable $phrases): array
    {
        $out = [];
        foreach ($phrases as $p) {
            $w = array_values(array_filter(explode(' ', self::fold((string) $p)), static fn ($x) => $x !== ''));
            for ($i = 0; $i + 1 < count($w); $i++) {
                $out[$w[$i].' '.$w[$i + 1]] = true;
            }
        }

        return $out;
    }

    /**
     * Contiguous word n-grams (display form) that neither start nor end with
     * a function word / intent modifier / bare number, and never cut a
     * known compound ("tai nghe" stays whole: no "5 tai", "nghe chống").
     *
     * @param  array<string, true>  $extraCompounds  folded "a b" pairs
     * @return list<string>
     */
    public static function ngrams(string $text, int $min = 2, int $max = 4, array $extraCompounds = []): array
    {
        $mods = self::$modifiers ??= array_fill_keys(self::MODIFIERS, true);
        $compounds = (self::$compounds ??= array_fill_keys(self::COMPOUNDS, true)) + $extraCompounds;
        $words = array_values(array_filter(explode(' ', self::normalize($text)), static fn ($w) => $w !== ''));
        $folded = array_map(static fn (string $w): string => self::fold($w), $words);
        $out = [];
        $n = count($words);
        for ($size = $min; $size <= $max; $size++) {
            for ($i = 0; $i + $size <= $n; $i++) {
                $j = $i + $size - 1;
                $first = $folded[$i];
                $last = $folded[$j];
                if (self::isStop($words[$i], $first) || self::isStop($words[$j], $last) || isset($mods[$first]) || isset($mods[$last]) || ctype_digit($first)) {
                    continue;
                }
                if (($i > 0 && isset($compounds[$folded[$i - 1].' '.$first])) || ($j + 1 < $n && isset($compounds[$last.' '.$folded[$j + 1]]))) {
                    continue;
                }
                $out[] = implode(' ', array_slice($words, $i, $size));
            }
        }

        return $out;
    }

    public static function clean(mixed $value, int $max = 500): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_substr($s, 0, $max);
    }

    /**
     * True when the text touches one of the excluded topics (folded,
     * whole-word or phrase match).
     *
     * @param  list<string>  $excluded
     */
    public static function matchesAny(string $text, array $excluded): bool
    {
        $hay = ' '.self::fold($text).' ';
        foreach ($excluded as $ex) {
            $needle = self::fold($ex);
            if ($needle !== '' && str_contains($hay, ' '.$needle.' ')) {
                return true;
            }
        }

        return false;
    }
}

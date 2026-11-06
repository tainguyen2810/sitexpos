// Dev-only mock of an OpenAI-compatible provider (GET /v1/models,
// POST /v1/chat/completions) for the custom-provider E2E. Requires
// `Authorization: Bearer sk-mock-e2e-key-0001`. Usage: node mock-openai-compatible.js [port]
const http = require('http')
const port = Number(process.argv[2] ?? 8099)
const KEY = 'Bearer sk-mock-e2e-key-0001'
const seen = []

http.createServer((req, res) => {
  let body = ''
  req.on('data', (c) => { body += c })
  req.on('end', () => {
    const send = (status, json) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(json)) }
    if (req.url === '/__seen') return send(200, seen)
    if (req.headers.authorization !== KEY) return send(401, { error: { message: 'Invalid API key' } })
    if (req.method === 'GET' && req.url === '/v1/models') return send(200, { object: 'list', data: [{ id: 'mock-mini', object: 'model', owned_by: 'mock' }, { id: 'mock-large', object: 'model', owned_by: 'mock' }] })
    if (req.method === 'POST' && req.url === '/v1/chat/completions') {
      const r = JSON.parse(body || '{}')
      seen.push({ model: r.model, response_format: r.response_format ?? null, max_tokens: r.max_tokens })
      const json = r.response_format ? '{"answer":"2 + 2 = 4","confident":true}' : '2 + 2 = 4.'
      return send(200, {
        id: 'mock-' + Date.now(), object: 'chat.completion', model: r.model,
        choices: [{ index: 0, message: { role: 'assistant', content: json }, finish_reason: 'stop' }],
        usage: { prompt_tokens: 120, completion_tokens: 12, total_tokens: 132, prompt_tokens_details: { cached_tokens: 20 } },
      })
    }
    send(404, { error: { message: 'not found' } })
  })
}).listen(port, '127.0.0.1', () => console.log(`mock OpenAI-compatible provider on http://127.0.0.1:${port}/v1`))

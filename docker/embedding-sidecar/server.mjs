import { pipeline, env } from '@huggingface/transformers';
import { createServer } from 'http';

const PORT = process.env.PORT || 3200;
const EMBEDDING_DIM = 256;

env.cacheDir = process.env.HF_CACHE_DIR || '/app/model-cache';

console.log('Loading nomic-embed-text-v1.5 model...');
const extractor = await pipeline(
  'feature-extraction',
  'nomic-ai/nomic-embed-text-v1.5',
  { quantized: true }
);
console.log('Model loaded.');

const server = createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }

  if (req.method !== 'POST' || req.url !== '/embed') {
    res.writeHead(404);
    res.end('Not found');
    return;
  }

  let body = '';
  for await (const chunk of req) body += chunk;

  try {
    const { texts } = JSON.parse(body);
    if (!Array.isArray(texts) || texts.length === 0) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'texts must be a non-empty array' }));
      return;
    }

    // Prefix for query embedding (vs "search_document:" for documents)
    const prefixed = texts.map(t => `search_query: ${t}`);
    const output = await extractor(prefixed, { pooling: 'mean', normalize: true });
    const dim = output.dims[1];

    const embeddings = [];
    for (let i = 0; i < texts.length; i++) {
      const vec = [];
      for (let j = 0; j < EMBEDDING_DIM; j++) {
        vec.push(output.data[i * dim + j]);
      }
      // Re-normalize after truncation
      let norm = 0;
      for (let j = 0; j < EMBEDDING_DIM; j++) norm += vec[j] * vec[j];
      norm = Math.sqrt(norm);
      embeddings.push(vec.map(v => v / norm));
    }

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ embeddings }));
  } catch (e) {
    res.writeHead(500, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: e.message }));
  }
});

server.listen(PORT, () => {
  console.log(`Embedding sidecar listening on port ${PORT}`);
});

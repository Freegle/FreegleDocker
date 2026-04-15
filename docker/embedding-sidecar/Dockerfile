FROM node:20-slim
WORKDIR /app
COPY package.json .
RUN npm install
COPY server.mjs .
# Pre-download model on build so startup is fast
RUN echo "import {pipeline,env} from '@huggingface/transformers'; env.cacheDir='/app/model-cache'; await pipeline('feature-extraction','nomic-ai/nomic-embed-text-v1.5',{quantized:true}); console.log('Model cached.');" > /app/prebuild.mjs && node /app/prebuild.mjs && rm /app/prebuild.mjs
EXPOSE 3200
CMD ["node", "server.mjs"]

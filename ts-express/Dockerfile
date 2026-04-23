FROM node:22-slim

WORKDIR /app

COPY package.json .
RUN npm install --omit=dev --ignore-scripts && \
    npm install typescript @types/node @types/express @types/pg --ignore-scripts

COPY tsconfig.json .
COPY src ./src

RUN npx tsc

EXPOSE 8000
CMD ["node", "dist/index.js"]

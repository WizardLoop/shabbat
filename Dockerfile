FROM hub.madelineproto.xyz/danog/madelineproto

RUN apk add --no-cache docker-cli docker-compose

WORKDIR /app

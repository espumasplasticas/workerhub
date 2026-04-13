FROM node:20-alpine

EXPOSE 6001

WORKDIR /app

COPY ./docker/echo-server/start.sh /app/start.sh

RUN npm install -g laravel-echo-server
RUN chmod +x /app/start.sh

CMD ["/app/start.sh"]

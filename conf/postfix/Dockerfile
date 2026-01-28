FROM alpine:3.19

RUN apk add --no-cache postfix postfix-pcre bash curl

# Create mail user for running the pipe
RUN adduser -D -u 1000 mail-handler

# Copy configuration files
COPY main.cf /etc/postfix/main.cf
COPY master.cf /etc/postfix/master.cf
COPY transport /etc/postfix/transport
COPY freegle-mail-handler /usr/local/bin/freegle-mail-handler
RUN chmod +x /usr/local/bin/freegle-mail-handler

# Build the transport map
RUN postmap /etc/postfix/transport

# Create spool directories
RUN mkdir -p /var/spool/postfix && \
    postfix set-permissions

# Expose SMTP port
EXPOSE 25

# Start postfix in foreground
CMD ["postfix", "start-fg"]

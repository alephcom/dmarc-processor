FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/smtp-receiver ./cmd/smtp-receiver

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/smtp-receiver /smtp-receiver
# Port 25 inside the container needs CAP_NET_BIND_SERVICE; we listen on 2525 and map 25->2525 in compose.
ENV SMTP_LISTEN=:2525 OPS_LISTEN=:9090 SPOOL_DIR=/spool
EXPOSE 2525 9090
USER nonroot
ENTRYPOINT ["/smtp-receiver"]

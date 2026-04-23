FROM golang:1.23 AS builder

WORKDIR /app

COPY go.mod *.go ./
RUN go mod tidy && CGO_ENABLED=0 GOOS=linux go build -o chopchop .

FROM gcr.io/distroless/static-debian12

WORKDIR /app
COPY --from=builder /app/chopchop .

EXPOSE 8000
CMD ["/app/chopchop"]

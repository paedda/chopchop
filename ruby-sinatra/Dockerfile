FROM ruby:3.3-slim

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
      build-essential libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY Gemfile ./
RUN bundle install --jobs 4 --retry 3

COPY . .

EXPOSE 8000
CMD ["bundle", "exec", "puma", "-p", "8000", "-b", "tcp://0.0.0.0"]

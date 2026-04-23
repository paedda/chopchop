CREATE TABLE IF NOT EXISTS links (
    id          SERIAL PRIMARY KEY,
    code        VARCHAR(20) UNIQUE NOT NULL,
    url         TEXT NOT NULL,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    expires_at  TIMESTAMP WITH TIME ZONE
);

CREATE INDEX IF NOT EXISTS idx_links_code ON links(code);

CREATE TABLE IF NOT EXISTS clicks (
    id          SERIAL PRIMARY KEY,
    link_id     INTEGER NOT NULL REFERENCES links(id),
    clicked_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    ip_address  VARCHAR(45),
    user_agent  TEXT,
    referer     TEXT
);

CREATE INDEX IF NOT EXISTS idx_clicks_link_id ON clicks(link_id);

import { Router, Request, Response, NextFunction } from "express";
import { pool } from "./db";
import { generateCode } from "./codegen";

const router = Router();

const URL_REGEX = /^https?:\/\/[^/]+\.[^/]/i;
const CODE_REGEX = /^[a-zA-Z0-9-]{3,20}$/;
const MAX_EXPIRES_IN = 2_592_000;

// Wraps an async route handler so that thrown errors reach Express's error handler.
function wrap(
  fn: (req: Request, res: Response, next: NextFunction) => Promise<void>
) {
  return (req: Request, res: Response, next: NextFunction) => fn(req, res, next).catch(next);
}

// GET /health
router.get("/health", (_req: Request, res: Response) => {
  res.json({ status: "ok", language: "typescript", framework: "express" });
});

// POST /chop
router.post(
  "/chop",
  wrap(async (req, res) => {
    const { url, custom_code, expires_in } = req.body ?? {};

    if (!url || typeof url !== "string" || !URL_REGEX.test(url)) {
      res.status(400).json({ error: "Invalid or missing URL" });
      return;
    }

    let code: string;

    if (custom_code !== undefined) {
      if (typeof custom_code !== "string" || !CODE_REGEX.test(custom_code)) {
        res.status(400).json({
          error: "custom_code must be 3–20 alphanumeric characters or hyphens",
        });
        return;
      }
      const existing = await pool.query("SELECT 1 FROM links WHERE code = $1", [custom_code]);
      if ((existing.rowCount ?? 0) > 0) {
        res.status(409).json({ error: "Custom code already taken" });
        return;
      }
      code = custom_code;
    } else {
      code = await generateCode(pool);
    }

    let expiresAt: Date | null = null;
    if (expires_in !== undefined) {
      if (!Number.isInteger(expires_in) || expires_in <= 0 || expires_in > MAX_EXPIRES_IN) {
        res.status(400).json({
          error: "expires_in must be a positive integer no greater than 2592000",
        });
        return;
      }
      expiresAt = new Date(Date.now() + expires_in * 1000);
    }

    const result = await pool.query(
      `INSERT INTO links (code, url, created_at, expires_at)
       VALUES ($1, $2, NOW(), $3)
       RETURNING code, url, created_at, expires_at`,
      [code, url, expiresAt]
    );

    const link = result.rows[0];
    const host = `${req.protocol}://${req.get("host")}`;

    res.status(201).json({
      code: link.code,
      short_url: `${host}/${link.code}`,
      url: link.url,
      created_at: link.created_at.toISOString(),
      expires_at: link.expires_at ? link.expires_at.toISOString() : null,
    });
  })
);

// GET /stats/:code
router.get(
  "/stats/:code",
  wrap(async (req, res) => {
    const { code } = req.params;

    const linkResult = await pool.query("SELECT * FROM links WHERE code = $1", [code]);
    if ((linkResult.rowCount ?? 0) === 0) {
      res.status(404).json({ error: "Link not found" });
      return;
    }

    const link = linkResult.rows[0];

    const clicksResult = await pool.query(
      `SELECT clicked_at, referer, user_agent
       FROM clicks
       WHERE link_id = $1
       ORDER BY clicked_at DESC`,
      [link.id]
    );

    const totalClicks = clicksResult.rowCount ?? 0;
    const recentClicks = clicksResult.rows.slice(0, 10).map((c) => ({
      clicked_at: c.clicked_at.toISOString(),
      referer: c.referer,
      user_agent: c.user_agent,
    }));

    res.json({
      code: link.code,
      url: link.url,
      created_at: link.created_at.toISOString(),
      expires_at: link.expires_at ? link.expires_at.toISOString() : null,
      total_clicks: totalClicks,
      recent_clicks: recentClicks,
    });
  })
);

// GET /:code — must be last to avoid shadowing static routes
router.get(
  "/:code",
  wrap(async (req, res) => {
    const { code } = req.params;

    const result = await pool.query("SELECT * FROM links WHERE code = $1", [code]);
    if ((result.rowCount ?? 0) === 0) {
      res.status(404).json({ error: "Link not found" });
      return;
    }

    const link = result.rows[0];

    if (link.expires_at && new Date(link.expires_at) < new Date()) {
      res.status(410).json({ error: "Link has expired" });
      return;
    }

    const ipAddress =
      (req.headers["x-forwarded-for"] as string | undefined)?.split(",")[0]?.trim() ??
      req.socket.remoteAddress ??
      null;

    await pool.query(
      `INSERT INTO clicks (link_id, clicked_at, ip_address, user_agent, referer)
       VALUES ($1, NOW(), $2, $3, $4)`,
      [link.id, ipAddress, req.get("user-agent") ?? null, req.get("referer") ?? null]
    );

    res.redirect(301, link.url);
  })
);

export default router;

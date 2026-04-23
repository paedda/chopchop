import express, { Request, Response, NextFunction } from "express";
import router from "./router";

const app = express();

app.use(express.json());
app.use(router);

// Catch-all error handler — returns {"error": "..."} for unhandled exceptions
app.use((err: unknown, _req: Request, res: Response, _next: NextFunction) => {
  console.error(err);
  res.status(500).json({ error: "Internal server error" });
});

const PORT = parseInt(process.env.PORT ?? "8000", 10);
app.listen(PORT, "0.0.0.0", () => {
  console.log(`ChopChop TypeScript/Express listening on port ${PORT}`);
});

package com.chopchop.controller;

import com.chopchop.model.Click;
import com.chopchop.model.Link;
import com.chopchop.repository.ClickRepository;
import com.chopchop.repository.LinkRepository;
import com.chopchop.service.CodeGenerator;
import jakarta.servlet.http.HttpServletRequest;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;

import java.net.URI;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.time.format.DateTimeFormatter;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Pattern;

@RestController
public class LinkController {

    private static final Pattern CODE_PATTERN = Pattern.compile("^[a-zA-Z0-9\\-]{3,20}$");
    private static final int MAX_EXPIRES_IN = 2_592_000;
    private static final DateTimeFormatter ISO = DateTimeFormatter.ISO_OFFSET_DATE_TIME;

    private final LinkRepository linkRepository;
    private final ClickRepository clickRepository;
    private final CodeGenerator codeGenerator;

    public LinkController(LinkRepository linkRepository, ClickRepository clickRepository, CodeGenerator codeGenerator) {
        this.linkRepository = linkRepository;
        this.clickRepository = clickRepository;
        this.codeGenerator = codeGenerator;
    }

    // GET /health
    @GetMapping("/health")
    public Map<String, String> health() {
        return Map.of("status", "ok", "language", "java", "framework", "spring boot");
    }

    // POST /chop
    @PostMapping("/chop")
    public ResponseEntity<Object> chop(@RequestBody Map<String, Object> body, HttpServletRequest request) {
        String url = asString(body.get("url"));
        Object customCodeObj = body.get("custom_code");
        Object expiresInObj = body.get("expires_in");

        if (url == null || !isValidUrl(url)) {
            return error(HttpStatus.BAD_REQUEST, "Invalid or missing URL");
        }

        String code;
        if (customCodeObj != null) {
            String customCode = asString(customCodeObj);
            if (customCode == null || !CODE_PATTERN.matcher(customCode).matches()) {
                return error(HttpStatus.BAD_REQUEST, "custom_code must be 3–20 alphanumeric characters or hyphens");
            }
            if (linkRepository.existsByCode(customCode)) {
                return error(HttpStatus.CONFLICT, "Custom code already taken");
            }
            code = customCode;
        } else {
            code = codeGenerator.generate();
        }

        OffsetDateTime expiresAt = null;
        if (expiresInObj != null) {
            if (!(expiresInObj instanceof Integer expiresIn) || expiresIn <= 0 || expiresIn > MAX_EXPIRES_IN) {
                return error(HttpStatus.BAD_REQUEST, "expires_in must be a positive integer no greater than 2592000");
            }
            expiresAt = OffsetDateTime.now(ZoneOffset.UTC).plusSeconds(expiresIn);
        }

        Link link = new Link();
        link.setCode(code);
        link.setUrl(url);
        link.setCreatedAt(OffsetDateTime.now(ZoneOffset.UTC));
        link.setExpiresAt(expiresAt);
        linkRepository.save(link);

        String base = request.getScheme() + "://" + request.getServerName()
                + (request.getServerPort() != 80 && request.getServerPort() != 443
                        ? ":" + request.getServerPort() : "");

        Map<String, Object> resp = new LinkedHashMap<>();
        resp.put("code", link.getCode());
        resp.put("short_url", base + "/" + link.getCode());
        resp.put("url", link.getUrl());
        resp.put("created_at", link.getCreatedAt().format(ISO));
        resp.put("expires_at", link.getExpiresAt() != null ? link.getExpiresAt().format(ISO) : null);

        return ResponseEntity.status(HttpStatus.CREATED).body(resp);
    }

    // GET /stats/:code
    @GetMapping("/stats/{code}")
    public ResponseEntity<Object> stats(@PathVariable String code) {
        Link link = linkRepository.findByCode(code).orElse(null);
        if (link == null) {
            return error(HttpStatus.NOT_FOUND, "Link not found");
        }

        long totalClicks = clickRepository.countByLinkId(link.getId());
        List<Click> recent = clickRepository.findTop10ByLinkIdOrderByClickedAtDesc(link.getId());

        Map<String, Object> resp = new LinkedHashMap<>();
        resp.put("code", link.getCode());
        resp.put("url", link.getUrl());
        resp.put("created_at", link.getCreatedAt().format(ISO));
        resp.put("expires_at", link.getExpiresAt() != null ? link.getExpiresAt().format(ISO) : null);
        resp.put("total_clicks", totalClicks);
        resp.put("recent_clicks", recent.stream().map(c -> {
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("clicked_at", c.getClickedAt().format(ISO));
            m.put("referer", c.getReferer());
            m.put("user_agent", c.getUserAgent());
            return m;
        }).toList());

        return ResponseEntity.ok(resp);
    }

    // GET /:code
    @GetMapping("/{code}")
    public ResponseEntity<Object> redirect(@PathVariable String code, HttpServletRequest request) {
        Link link = linkRepository.findByCode(code).orElse(null);
        if (link == null) {
            return error(HttpStatus.NOT_FOUND, "Link not found");
        }

        if (link.getExpiresAt() != null && link.getExpiresAt().isBefore(OffsetDateTime.now(ZoneOffset.UTC))) {
            return error(HttpStatus.GONE, "Link has expired");
        }

        Click click = new Click();
        click.setLink(link);
        click.setClickedAt(OffsetDateTime.now(ZoneOffset.UTC));
        click.setIpAddress(clientIp(request));
        click.setUserAgent(request.getHeader("User-Agent"));
        click.setReferer(request.getHeader("Referer"));
        clickRepository.save(click);

        return ResponseEntity.status(HttpStatus.MOVED_PERMANENTLY)
                .location(URI.create(link.getUrl()))
                .build();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private static boolean isValidUrl(String url) {
        if (!url.startsWith("http://") && !url.startsWith("https://")) return false;
        try {
            URI uri = URI.create(url);
            String host = uri.getHost();
            return host != null && host.contains(".");
        } catch (IllegalArgumentException e) {
            return false;
        }
    }

    private static String asString(Object o) {
        return (o instanceof String s) ? s : null;
    }

    private static String clientIp(HttpServletRequest request) {
        String forwarded = request.getHeader("X-Forwarded-For");
        if (forwarded != null && !forwarded.isBlank()) {
            return forwarded.split(",")[0].trim();
        }
        return request.getRemoteAddr();
    }

    private static ResponseEntity<Object> error(HttpStatus status, String message) {
        return ResponseEntity.status(status).body(Map.of("error", message));
    }
}

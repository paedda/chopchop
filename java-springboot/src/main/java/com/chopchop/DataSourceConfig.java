package com.chopchop;

import com.zaxxer.hikari.HikariConfig;
import com.zaxxer.hikari.HikariDataSource;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.context.annotation.Primary;

import javax.sql.DataSource;
import java.net.URI;

/**
 * Parses the DATABASE_URL env var (postgresql://user:pass@host:port/db)
 * into a HikariCP DataSource, since JDBC does not support that URL format natively.
 */
@Configuration
public class DataSourceConfig {

    @Bean
    @Primary
    public DataSource dataSource() {
        String raw = System.getenv("DATABASE_URL");
        if (raw == null || raw.isBlank()) {
            throw new IllegalStateException("DATABASE_URL environment variable is required");
        }

        URI uri = URI.create(raw);
        String[] userInfo = uri.getUserInfo().split(":", 2);
        String jdbcUrl = "jdbc:postgresql://" + uri.getHost() + ":" + uri.getPort() + uri.getPath();

        HikariConfig config = new HikariConfig();
        config.setJdbcUrl(jdbcUrl);
        config.setUsername(userInfo[0]);
        config.setPassword(userInfo.length > 1 ? userInfo[1] : "");
        config.setMaximumPoolSize(10);
        return new HikariDataSource(config);
    }
}

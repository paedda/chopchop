package com.chopchop.repository;

import com.chopchop.model.Link;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.Optional;

public interface LinkRepository extends JpaRepository<Link, Long> {
    Optional<Link> findByCode(String code);
    boolean existsByCode(String code);

    @Query("SELECT l FROM Link l LEFT JOIN FETCH l.clicks c WHERE l.code = :code ORDER BY c.clickedAt DESC")
    Optional<Link> findByCodeWithClicks(@Param("code") String code);
}

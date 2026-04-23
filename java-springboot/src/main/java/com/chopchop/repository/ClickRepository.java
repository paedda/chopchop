package com.chopchop.repository;

import com.chopchop.model.Click;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

public interface ClickRepository extends JpaRepository<Click, Long> {
    long countByLinkId(Long linkId);

    @Query("SELECT c FROM Click c WHERE c.link.id = :linkId ORDER BY c.clickedAt DESC LIMIT 10")
    java.util.List<Click> findTop10ByLinkIdOrderByClickedAtDesc(@Param("linkId") Long linkId);
}

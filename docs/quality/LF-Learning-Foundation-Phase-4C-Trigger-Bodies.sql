-- Review-only candidate SQL. Not authorized for migration or deployment.

CREATE TRIGGER trg_lrn_frameworks_bi_scale BEFORE INSERT ON core_learning_frameworks FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.default_mastery_scale) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, '$.levels')), '') <> 'ARRAY'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.default_mastery_scale, '$.levels')), 0) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_SCALE_INVALID';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.default_mastery_scale, '$.levels'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index,'].key')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index,'].threshold'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index,'].key'))), '') <> 'STRING'
           OR NULLIF(TRIM(scale_key), '') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index,'].threshold'))), '') NOT IN ('INTEGER','DOUBLE')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index - 1,'].threshold'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.default_mastery_scale, 'one', scale_key, NULL, '$.levels[*].key')) <> CONCAT('$.levels[',scale_index,'].key') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_SCALE_INVALID';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NEW.status <> 'active' OR NEW.archived_at IS NOT NULL OR NEW.archived_by IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_FRAMEWORK_LIFECYCLE_INVALID';
    END IF;
END;

CREATE TRIGGER trg_lrn_frameworks_bu_scale BEFORE UPDATE ON core_learning_frameworks FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.default_mastery_scale) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, '$.levels')), '') <> 'ARRAY'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.default_mastery_scale, '$.levels')), 0) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_SCALE_INVALID';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.default_mastery_scale, '$.levels'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index,'].key')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index,'].threshold'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index,'].key'))), '') <> 'STRING'
           OR NULLIF(TRIM(scale_key), '') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index,'].threshold'))), '') NOT IN ('INTEGER','DOUBLE')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.default_mastery_scale, CONCAT('$.levels[',scale_index - 1,'].threshold'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.default_mastery_scale, 'one', scale_key, NULL, '$.levels[*].key')) <> CONCAT('$.levels[',scale_index,'].key') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_SCALE_INVALID';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NOT ((OLD.status = 'active' AND NEW.status = 'active'
             AND NEW.archived_at IS NULL AND NEW.archived_by IS NULL)
         OR (OLD.status = 'active' AND NEW.status = 'archived'
             AND NEW.archived_at IS NOT NULL AND NEW.archived_by IS NOT NULL)
         OR (OLD.status = 'archived' AND NEW.status = 'archived'
             AND NEW.archived_at <=> OLD.archived_at
             AND NEW.archived_by <=> OLD.archived_by)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_FRAMEWORK_LIFECYCLE_INVALID';
    END IF;
    IF OLD.status = 'archived' AND (
       NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.code <=> OLD.code)
       OR NOT (NEW.name <=> OLD.name) OR NOT (NEW.description <=> OLD.description)
       OR NOT (NEW.default_mastery_scale_key <=> OLD.default_mastery_scale_key)
       OR NOT (NEW.default_mastery_scale_version <=> OLD.default_mastery_scale_version)
       OR NOT (NEW.default_mastery_scale <=> OLD.default_mastery_scale)
       OR NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.updated_by <=> OLD.updated_by)
       OR NOT (NEW.created_at <=> OLD.created_at) OR NOT (NEW.updated_at <=> OLD.updated_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_FRAMEWORK_LIFECYCLE_INVALID';
    END IF;
END;

CREATE TRIGGER trg_lrn_fw_versions_bi_validate BEFORE INSERT ON core_learning_framework_versions FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.mastery_scale_snapshot) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels')), '') <> 'ARRAY'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels')), 0) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_SCALE_INVALID';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].key')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].threshold'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].key'))), '') <> 'STRING'
           OR NULLIF(TRIM(scale_key), '') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].threshold'))), '') NOT IN ('INTEGER','DOUBLE')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index - 1,'].threshold'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.mastery_scale_snapshot, 'one', scale_key, NULL, '$.levels[*].key')) <> CONCAT('$.levels[',scale_index,'].key') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_SCALE_INVALID';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NEW.status <> 'draft_snapshot' OR NEW.published_at IS NOT NULL
       OR NEW.deprecated_at IS NOT NULL OR NEW.archived_at IS NOT NULL
       OR NEW.published_by IS NOT NULL OR NEW.deprecated_by IS NOT NULL
       OR NEW.archived_by IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_VERSION_LIFECYCLE_INVALID';
    END IF;
END;

CREATE TRIGGER trg_lrn_fw_versions_bu_immutable BEFORE UPDATE ON core_learning_framework_versions FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.mastery_scale_snapshot) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels')), '') <> 'ARRAY'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels')), 0) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_SCALE_INVALID';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].key')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].threshold'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].key'))), '') <> 'STRING'
           OR NULLIF(TRIM(scale_key), '') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].threshold'))), '') NOT IN ('INTEGER','DOUBLE')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index - 1,'].threshold'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.mastery_scale_snapshot, 'one', scale_key, NULL, '$.levels[*].key')) <> CONCAT('$.levels[',scale_index,'].key') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_SCALE_INVALID';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NOT (NEW.customer_id <=> OLD.customer_id)
       OR NOT (NEW.framework_id <=> OLD.framework_id)
       OR NOT (NEW.version_number <=> OLD.version_number)
       OR NOT (NEW.version_code <=> OLD.version_code)
       OR NOT (NEW.created_by <=> OLD.created_by)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_VERSION_IMMUTABLE';
    END IF;
    IF OLD.status <> 'draft_snapshot' AND (
       NOT (NEW.title_snapshot <=> OLD.title_snapshot)
       OR NOT (NEW.description_snapshot <=> OLD.description_snapshot)
       OR NOT (NEW.mastery_scale_key <=> OLD.mastery_scale_key)
       OR NOT (NEW.mastery_scale_version <=> OLD.mastery_scale_version)
       OR NOT (NEW.mastery_scale_snapshot <=> OLD.mastery_scale_snapshot)
       OR NOT (NEW.updated_by <=> OLD.updated_by)
       OR NOT (NEW.updated_at <=> OLD.updated_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_VERSION_IMMUTABLE';
    END IF;
    IF NOT ((OLD.status = 'draft_snapshot' AND NEW.status = 'draft_snapshot'
             AND NEW.published_at IS NULL AND NEW.published_by IS NULL
             AND NEW.deprecated_at IS NULL AND NEW.deprecated_by IS NULL
             AND NEW.archived_at IS NULL AND NEW.archived_by IS NULL)
         OR (OLD.status = 'draft_snapshot' AND NEW.status = 'published'
             AND NEW.published_at IS NOT NULL AND NEW.published_by IS NOT NULL
             AND NEW.deprecated_at IS NULL AND NEW.deprecated_by IS NULL
             AND NEW.archived_at IS NULL AND NEW.archived_by IS NULL)
         OR (OLD.status = 'published' AND NEW.status = 'deprecated'
             AND NEW.published_at <=> OLD.published_at
             AND NEW.published_by <=> OLD.published_by
             AND NEW.deprecated_at IS NOT NULL AND NEW.deprecated_by IS NOT NULL
             AND NEW.archived_at IS NULL AND NEW.archived_by IS NULL)
         OR (OLD.status = 'deprecated' AND NEW.status = 'archived'
             AND NEW.published_at <=> OLD.published_at
             AND NEW.published_by <=> OLD.published_by
             AND NEW.deprecated_at <=> OLD.deprecated_at
             AND NEW.deprecated_by <=> OLD.deprecated_by
             AND NEW.archived_at IS NOT NULL AND NEW.archived_by IS NOT NULL)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_VERSION_LIFECYCLE_INVALID';
    END IF;
END;

CREATE TRIGGER trg_lrn_fw_versions_bd_immutable BEFORE DELETE ON core_learning_framework_versions FOR EACH ROW
BEGIN
    IF OLD.status <> 'draft_snapshot' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_VERSION_DELETE_FORBIDDEN';
    END IF;
END;

CREATE TRIGGER trg_lrn_definitions_bu_identity BEFORE UPDATE ON core_learning_node_definitions FOR EACH ROW
BEGIN
    IF NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.framework_id <=> OLD.framework_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_DEFINITION_IDENTITY_IMMUTABLE';
    END IF;
END;

CREATE TRIGGER trg_lrn_nodes_bu_immutable BEFORE UPDATE ON core_learning_nodes FOR EACH ROW
BEGIN
    DECLARE version_status VARCHAR(50);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    IF NOT (NEW.customer_id <=> OLD.customer_id)
       OR NOT (NEW.framework_id <=> OLD.framework_id)
       OR NOT (NEW.framework_version_id <=> OLD.framework_version_id)
       OR NOT (NEW.node_definition_id <=> OLD.node_definition_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_NODE_IDENTITY_IMMUTABLE';
    END IF;
    SELECT status INTO version_status FROM core_learning_framework_versions
     WHERE id = OLD.framework_version_id AND customer_id = OLD.customer_id
       AND framework_id = OLD.framework_id;
    IF parent_found = FALSE OR version_status <> 'draft_snapshot' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_NODE_IMMUTABLE';
    END IF;
END;

CREATE TRIGGER trg_lrn_nodes_bd_immutable BEFORE DELETE ON core_learning_nodes FOR EACH ROW
BEGIN
    DECLARE version_status VARCHAR(50);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    SELECT status INTO version_status FROM core_learning_framework_versions
     WHERE id = OLD.framework_version_id AND customer_id = OLD.customer_id
       AND framework_id = OLD.framework_id;
    IF parent_found = FALSE OR version_status <> 'draft_snapshot' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_NODE_DELETE_FORBIDDEN';
    END IF;
END;

CREATE TRIGGER trg_lrn_relations_bi_validate BEFORE INSERT ON core_learning_node_relations FOR EACH ROW
BEGIN
    IF NOT ((NEW.relation_scope = 'semantic'
             AND NEW.relation_type IN ('prerequisite','part_of','supports')
             AND NEW.source_framework_version_id = NEW.target_framework_version_id
             AND NEW.owning_framework_version_id = NEW.source_framework_version_id
             AND NEW.continuity_policy IS NULL AND NEW.continuity_policy_key IS NULL
             AND NEW.continuity_policy_version IS NULL AND NEW.continuity_policy_snapshot IS NULL
             AND NEW.review_status = 'not_required'
             AND NEW.resolved_continuity_policy IS NULL
             AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL
             AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL
             AND NEW.review_reason IS NULL)
         OR (NEW.relation_scope = 'version_transition'
             AND NEW.relation_type IN ('equivalent_to','supersedes','splits_into','merges_into')
             AND NEW.source_framework_version_id <> NEW.target_framework_version_id
             AND NEW.owning_framework_version_id = NEW.target_framework_version_id
             AND NEW.continuity_policy IN ('no_carry_forward','allow_as_input','carry_forward','requires_review')
             AND NEW.continuity_policy_key IS NOT NULL
             AND NEW.continuity_policy_version IS NOT NULL
             AND NEW.continuity_policy_snapshot IS NOT NULL
             AND JSON_VALID(NEW.continuity_policy_snapshot) = 1
             AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.source_framework_version_id')) AS UNSIGNED) = NEW.source_framework_version_id, FALSE)
             AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.target_framework_version_id')) AS UNSIGNED) = NEW.target_framework_version_id, FALSE)
             AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.policy')) = NEW.continuity_policy, FALSE)
             AND ((NEW.continuity_policy = 'requires_review'
                   AND NEW.review_status = 'pending'
                   AND NEW.resolved_continuity_policy IS NULL
                   AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL
                   AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL
                   AND NEW.review_reason IS NULL)
               OR (NEW.continuity_policy <> 'requires_review'
                   AND NEW.review_status = 'not_required'
                   AND NEW.resolved_continuity_policy IS NULL
                   AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL
                   AND NEW.review_reason IS NULL
                   AND ((NEW.continuity_policy IN ('allow_as_input','carry_forward')
                         AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL)
                     OR (NEW.continuity_policy = 'no_carry_forward'
                         AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL)))
               OR (NEW.continuity_policy <> 'requires_review'
                   AND NEW.review_status = 'approved'
                   AND NEW.resolved_continuity_policy IN ('no_carry_forward','allow_as_input','carry_forward')
                   AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL
                   AND NEW.reviewed_by = NEW.approved_by AND NEW.reviewed_at = NEW.approved_at
                   AND NULLIF(TRIM(NEW.review_reason), '') IS NOT NULL)))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_RELATION_INVALID';
    END IF;
    IF NEW.relation_scope = 'semantic' AND NEW.relation_type IN ('prerequisite','part_of')
       AND EXISTS (
          WITH RECURSIVE graph_walk AS (
            SELECT target_learning_node_id AS node_id
              FROM core_learning_node_relations
             WHERE customer_id = NEW.customer_id AND framework_id = NEW.framework_id
               AND relation_scope = 'semantic' AND relation_type = NEW.relation_type
               AND source_framework_version_id = NEW.source_framework_version_id
               AND source_learning_node_id = NEW.target_learning_node_id
            UNION DISTINCT
            SELECT relation_row.target_learning_node_id
              FROM core_learning_node_relations relation_row
              JOIN graph_walk ON relation_row.source_learning_node_id = graph_walk.node_id
             WHERE relation_row.customer_id = NEW.customer_id
               AND relation_row.framework_id = NEW.framework_id
               AND relation_row.relation_scope = 'semantic'
               AND relation_row.relation_type = NEW.relation_type
               AND relation_row.source_framework_version_id = NEW.source_framework_version_id
          ) SELECT 1 FROM graph_walk WHERE node_id = NEW.source_learning_node_id
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_RELATION_CYCLE';
    END IF;
END;

CREATE TRIGGER trg_lrn_relations_bu_immutable BEFORE UPDATE ON core_learning_node_relations FOR EACH ROW
BEGIN
    DECLARE version_status VARCHAR(50);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    IF NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.framework_id <=> OLD.framework_id)
       OR NOT (NEW.owning_framework_version_id <=> OLD.owning_framework_version_id)
       OR NOT (NEW.relation_scope <=> OLD.relation_scope) OR NOT (NEW.relation_type <=> OLD.relation_type)
       OR NOT (NEW.source_learning_node_id <=> OLD.source_learning_node_id)
       OR NOT (NEW.target_learning_node_id <=> OLD.target_learning_node_id)
       OR NOT (NEW.source_framework_version_id <=> OLD.source_framework_version_id)
       OR NOT (NEW.target_framework_version_id <=> OLD.target_framework_version_id)
       OR NOT (NEW.continuity_policy <=> OLD.continuity_policy)
       OR NOT (NEW.continuity_policy_key <=> OLD.continuity_policy_key)
       OR NOT (NEW.continuity_policy_version <=> OLD.continuity_policy_version)
       OR NOT (NEW.continuity_policy_snapshot <=> OLD.continuity_policy_snapshot)
       OR NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_RELATION_IMMUTABLE';
    END IF;
    SELECT status INTO version_status FROM core_learning_framework_versions
     WHERE id = OLD.owning_framework_version_id AND customer_id = OLD.customer_id
       AND framework_id = OLD.framework_id;
    IF version_status = 'draft_snapshot' AND NOT (
       (NEW.relation_scope = 'semantic'
        AND NEW.relation_type IN ('prerequisite','part_of','supports')
        AND NEW.source_framework_version_id = NEW.target_framework_version_id
        AND NEW.owning_framework_version_id = NEW.source_framework_version_id
        AND NEW.continuity_policy IS NULL AND NEW.continuity_policy_key IS NULL
        AND NEW.continuity_policy_version IS NULL AND NEW.continuity_policy_snapshot IS NULL
        AND NEW.review_status = 'not_required' AND NEW.resolved_continuity_policy IS NULL
        AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL
        AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL AND NEW.review_reason IS NULL)
       OR
       (NEW.relation_scope = 'version_transition'
        AND NEW.relation_type IN ('equivalent_to','supersedes','splits_into','merges_into')
        AND NEW.source_framework_version_id <> NEW.target_framework_version_id
        AND NEW.owning_framework_version_id = NEW.target_framework_version_id
        AND NEW.continuity_policy IN ('no_carry_forward','allow_as_input','carry_forward','requires_review')
        AND NEW.continuity_policy_key IS NOT NULL AND NEW.continuity_policy_version IS NOT NULL
        AND NEW.continuity_policy_snapshot IS NOT NULL
        AND JSON_VALID(NEW.continuity_policy_snapshot) = 1
        AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.source_framework_version_id')) AS UNSIGNED) = NEW.source_framework_version_id, FALSE)
        AND COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.target_framework_version_id')) AS UNSIGNED) = NEW.target_framework_version_id, FALSE)
        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.policy')) = NEW.continuity_policy, FALSE)
        AND ((NEW.continuity_policy = 'requires_review' AND NEW.review_status = 'pending'
              AND NEW.resolved_continuity_policy IS NULL AND NEW.approved_by IS NULL
              AND NEW.approved_at IS NULL AND NEW.reviewed_by IS NULL
              AND NEW.reviewed_at IS NULL AND NEW.review_reason IS NULL)
          OR (NEW.continuity_policy <> 'requires_review' AND NEW.review_status = 'not_required'
              AND NEW.resolved_continuity_policy IS NULL AND NEW.reviewed_by IS NULL
              AND NEW.reviewed_at IS NULL AND NEW.review_reason IS NULL
              AND ((NEW.continuity_policy IN ('allow_as_input','carry_forward')
                    AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL)
                OR (NEW.continuity_policy = 'no_carry_forward'
                    AND NEW.approved_by IS NULL AND NEW.approved_at IS NULL)))
          OR (NEW.continuity_policy <> 'requires_review' AND NEW.review_status = 'approved'
              AND NEW.resolved_continuity_policy IN ('no_carry_forward','allow_as_input','carry_forward')
              AND NEW.approved_by IS NOT NULL AND NEW.approved_at IS NOT NULL
              AND NEW.reviewed_by = NEW.approved_by AND NEW.reviewed_at = NEW.approved_at
              AND NULLIF(TRIM(NEW.review_reason), '') IS NOT NULL))
       )) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_RELATION_INVALID';
    END IF;
    IF parent_found = FALSE THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_RELATION_INVALID';
    END IF;
    IF version_status <> 'draft_snapshot' AND NOT (
       OLD.review_status = 'pending' AND NEW.review_status IN ('approved','rejected')
       AND OLD.reviewed_by IS NULL AND OLD.reviewed_at IS NULL
       AND NEW.reviewed_by IS NOT NULL AND NEW.reviewed_at IS NOT NULL
       AND NULLIF(TRIM(NEW.review_reason), '') IS NOT NULL
       AND ((NEW.review_status = 'approved' AND NEW.approved_by IS NOT NULL
             AND NEW.approved_at IS NOT NULL
             AND NEW.approved_by = NEW.reviewed_by
             AND NEW.approved_at = NEW.reviewed_at
             AND NEW.resolved_continuity_policy IN ('no_carry_forward','allow_as_input','carry_forward'))
         OR (NEW.review_status = 'rejected' AND NEW.approved_by IS NULL
             AND NEW.approved_at IS NULL AND NEW.resolved_continuity_policy IS NULL))
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_RELATION_IMMUTABLE';
    END IF;
END;

CREATE TRIGGER trg_lrn_relations_bd_immutable BEFORE DELETE ON core_learning_node_relations FOR EACH ROW
BEGIN
    DECLARE version_status VARCHAR(50);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    SELECT status INTO version_status FROM core_learning_framework_versions
     WHERE id = OLD.owning_framework_version_id AND customer_id = OLD.customer_id
       AND framework_id = OLD.framework_id;
    IF parent_found = FALSE OR version_status <> 'draft_snapshot' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_RELATION_DELETE_FORBIDDEN';
    END IF;
END;

CREATE TRIGGER trg_lrn_mappings_bu_lifecycle BEFORE UPDATE ON core_learning_node_mappings FOR EACH ROW
BEGIN
    IF NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.learning_node_id <=> OLD.learning_node_id)
       OR NOT (NEW.source_type <=> OLD.source_type) OR NOT (NEW.source_id <=> OLD.source_id)
       OR NOT (NEW.source_discriminator <=> OLD.source_discriminator)
       OR NOT (NEW.mapping_role <=> OLD.mapping_role) OR NOT (NEW.weight <=> OLD.weight)
       OR NOT (NEW.source_snapshot <=> OLD.source_snapshot) OR NOT (NEW.created_by <=> OLD.created_by)
       OR NOT (NEW.created_at <=> OLD.created_at)
       OR OLD.invalidated_at IS NOT NULL OR OLD.invalidated_by IS NOT NULL
       OR OLD.invalidation_reason IS NOT NULL OR NEW.invalidated_at IS NULL
       OR NEW.invalidated_by IS NULL OR NULLIF(TRIM(NEW.invalidation_reason), '') IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_MAPPING_IMMUTABLE';
    END IF;
END;

CREATE TRIGGER trg_lrn_mappings_bd_immutable BEFORE DELETE ON core_learning_node_mappings FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_MAPPING_IMMUTABLE';
END;

CREATE TRIGGER trg_lrn_evidence_bi_validate BEFORE INSERT ON core_learning_evidence FOR EACH ROW
BEGIN
    IF NEW.source_type <> 'teacher_judgment' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_EVIDENCE_SOURCE_CLOSED';
    END IF;
    IF NEW.evidence_type <> 'expert_judgment' OR NEW.recorded_by IS NULL OR NEW.source_id = 0
       OR NULLIF(TRIM(NEW.source_discriminator), '') IS NULL
       OR NULLIF(TRIM(NEW.producer_idempotency_key), '') IS NULL
       OR JSON_VALID(NEW.qualification_rule_snapshot) = 0
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.qualification_rule_snapshot, '$.rule_key')) = NEW.qualification_rule_key, FALSE) = FALSE
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.qualification_rule_snapshot, '$.rule_version')) = NEW.qualification_rule_version, FALSE) = FALSE
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.qualification_rule_snapshot, '$.source_type')) = NEW.source_type, FALSE) = FALSE
       OR (NEW.evaluated_at < NEW.source_occurred_at
           AND COALESCE(JSON_EXTRACT(NEW.qualification_rule_snapshot, '$.delayed_source_approved') = TRUE, FALSE) = FALSE)
       OR (NEW.supersedes_evidence_id IS NOT NULL AND NOT EXISTS (
           SELECT 1 FROM core_learning_evidence prior
            WHERE prior.id = NEW.supersedes_evidence_id AND prior.customer_id = NEW.customer_id
              AND prior.user_id = NEW.user_id AND prior.id <> NEW.id)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_EVIDENCE_INVALID';
    END IF;
END;

CREATE TRIGGER trg_lrn_evidence_bu_immutable BEFORE UPDATE ON core_learning_evidence FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_EVIDENCE_IMMUTABLE';
END;

CREATE TRIGGER trg_lrn_evidence_bd_immutable BEFORE DELETE ON core_learning_evidence FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_EVIDENCE_IMMUTABLE';
END;

CREATE TRIGGER trg_lrn_calcs_bi_validate BEFORE INSERT ON core_learning_mastery_calculations FOR EACH ROW
BEGIN
    DECLARE scale_index INT DEFAULT 0;
    DECLARE scale_count INT;
    DECLARE scale_key VARCHAR(255);
    DECLARE scale_threshold DECIMAL(18,6);
    IF JSON_VALID(NEW.mastery_scale_snapshot) = 0
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels')), '') <> 'ARRAY'
       OR COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels')), 0) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_CALCULATION_INVALID';
    END IF;
    SET scale_count = JSON_LENGTH(JSON_EXTRACT(NEW.mastery_scale_snapshot, '$.levels'));
    WHILE scale_index < scale_count DO
        SET scale_key = JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].key')));
        SET scale_threshold = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].threshold'))) AS DECIMAL(18,6));
        IF COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].key'))), '') <> 'STRING'
           OR NULLIF(TRIM(scale_key), '') IS NULL
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index,'].threshold'))), '') NOT IN ('INTEGER','DOUBLE')
           OR (scale_index > 0 AND scale_threshold <= CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.mastery_scale_snapshot, CONCAT('$.levels[',scale_index - 1,'].threshold'))) AS DECIMAL(18,6)))
           OR JSON_UNQUOTE(JSON_SEARCH(NEW.mastery_scale_snapshot, 'one', scale_key, NULL, '$.levels[*].key')) <> CONCAT('$.levels[',scale_index,'].key') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_CALCULATION_INVALID';
        END IF;
        SET scale_index = scale_index + 1;
    END WHILE;
    IF NOT EXISTS (
        SELECT 1 FROM core_learning_framework_versions basis
         WHERE basis.id = NEW.basis_framework_version_id AND basis.customer_id = NEW.customer_id
           AND basis.framework_id = NEW.framework_id
           AND basis.mastery_scale_key = NEW.mastery_scale_key
           AND basis.mastery_scale_version = NEW.mastery_scale_version
           AND basis.mastery_scale_snapshot = NEW.mastery_scale_snapshot)
       OR JSON_SEARCH(NEW.mastery_scale_snapshot, 'one', NEW.mastery_level_key, NULL, '$.levels[*].key') IS NULL
       OR JSON_VALID(NEW.calculation_rule_snapshot) = 0
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.calculation_rule_snapshot, '$.rule_key')) = NEW.calculation_rule_key, FALSE) = FALSE
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.calculation_rule_snapshot, '$.rule_version')) = NEW.calculation_rule_version, FALSE) = FALSE
       OR NOT ((NEW.calculation_source = 'system' AND NEW.calculated_by IS NULL
                AND NEW.reason IS NULL AND NEW.source_node_relation_id IS NULL
                AND NEW.continuity_policy_snapshot IS NULL)
            OR (NEW.calculation_source = 'teacher_override' AND NEW.calculated_by IS NOT NULL
                AND NULLIF(TRIM(NEW.reason), '') IS NOT NULL
                AND NEW.source_node_relation_id IS NULL AND NEW.continuity_policy_snapshot IS NULL)
            OR (NEW.calculation_source = 'carry_forward' AND NEW.source_calculation_id IS NOT NULL
                AND NEW.source_node_relation_id IS NOT NULL AND NEW.continuity_policy_snapshot IS NOT NULL
                AND JSON_VALID(NEW.continuity_policy_snapshot) = 1
                AND EXISTS (
                  SELECT 1 FROM core_learning_node_relations relation_row
                   WHERE relation_row.id = NEW.source_node_relation_id
                     AND relation_row.customer_id = NEW.customer_id
                     AND relation_row.framework_id = NEW.framework_id
                     AND relation_row.target_framework_version_id = NEW.basis_framework_version_id
                     AND relation_row.review_status = 'approved'
                     AND COALESCE(relation_row.resolved_continuity_policy,
                                  relation_row.continuity_policy) IN ('allow_as_input','carry_forward')
                     AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.relation_id')) AS UNSIGNED) = relation_row.id
                     AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.source_framework_version_id')) AS UNSIGNED) = relation_row.source_framework_version_id
                     AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.target_framework_version_id')) AS UNSIGNED) = relation_row.target_framework_version_id
                     AND JSON_UNQUOTE(JSON_EXTRACT(NEW.continuity_policy_snapshot, '$.policy')) = COALESCE(relation_row.resolved_continuity_policy, relation_row.continuity_policy)
                ))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_CALCULATION_INVALID';
    END IF;
END;

CREATE TRIGGER trg_lrn_calcs_bu_immutable BEFORE UPDATE ON core_learning_mastery_calculations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_CALCULATION_IMMUTABLE';
END;

CREATE TRIGGER trg_lrn_calcs_bd_immutable BEFORE DELETE ON core_learning_mastery_calculations FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_CALCULATION_IMMUTABLE';
END;

CREATE TRIGGER trg_lrn_calc_evidence_bi_validate BEFORE INSERT ON core_learning_calculation_evidence FOR EACH ROW
BEGIN
    IF NOT EXISTS (
       SELECT 1
         FROM core_learning_mastery_calculations calculation_row
         JOIN core_learning_evidence evidence_row
           ON evidence_row.id = NEW.evidence_id AND evidence_row.customer_id = NEW.customer_id
          AND evidence_row.user_id = NEW.user_id
         JOIN core_learning_nodes evidence_node ON evidence_node.id = evidence_row.learning_node_id
          AND evidence_node.customer_id = evidence_row.customer_id
        WHERE calculation_row.id = NEW.mastery_calculation_id
          AND calculation_row.customer_id = NEW.customer_id
          AND calculation_row.user_id = NEW.user_id
          AND (evidence_node.node_definition_id = calculation_row.node_definition_id
            OR (calculation_row.calculation_source = 'carry_forward'
                AND NEW.evidence_role = 'continuity_input'
                AND EXISTS (
                  SELECT 1 FROM core_learning_node_relations relation_row
                  JOIN core_learning_nodes source_node
                    ON source_node.id = relation_row.source_learning_node_id
                   AND source_node.customer_id = relation_row.customer_id
                  JOIN core_learning_nodes target_node
                    ON target_node.id = relation_row.target_learning_node_id
                   AND target_node.customer_id = relation_row.customer_id
                  WHERE relation_row.id = calculation_row.source_node_relation_id
                    AND relation_row.review_status = 'approved'
                    AND source_node.node_definition_id = evidence_node.node_definition_id
                    AND target_node.node_definition_id = calculation_row.node_definition_id
                    AND COALESCE(relation_row.resolved_continuity_policy,
                                 relation_row.continuity_policy) IN ('allow_as_input','carry_forward')
                )))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_CALC_EVIDENCE_INVALID';
    END IF;
END;

CREATE TRIGGER trg_lrn_calc_evidence_bu_immutable BEFORE UPDATE ON core_learning_calculation_evidence FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_CALC_EVIDENCE_IMMUTABLE';
END;

CREATE TRIGGER trg_lrn_calc_evidence_bd_immutable BEFORE DELETE ON core_learning_calculation_evidence FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_CALC_EVIDENCE_IMMUTABLE';
END;

CREATE TRIGGER trg_lrn_profiles_bi_projection BEFORE INSERT ON core_learning_mastery_profiles FOR EACH ROW
BEGIN
    IF NOT EXISTS (
       SELECT 1 FROM core_learning_mastery_calculations calculation_row
        WHERE calculation_row.id = NEW.current_calculation_id
          AND calculation_row.customer_id = NEW.customer_id AND calculation_row.user_id = NEW.user_id
          AND calculation_row.framework_id = NEW.framework_id
          AND calculation_row.node_definition_id = NEW.node_definition_id
          AND calculation_row.basis_framework_version_id = NEW.basis_framework_version_id
          AND calculation_row.mastery_level_key = NEW.mastery_level_key
          AND calculation_row.mastery_score <=> NEW.mastery_score
          AND calculation_row.mastery_status_result = NEW.mastery_status
          AND calculation_row.calculated_at = NEW.calculated_at
          AND calculation_row.reassessment_due_at <=> NEW.reassessment_due_at
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROFILE_MISMATCH';
    END IF;
END;

CREATE TRIGGER trg_lrn_profiles_bu_projection BEFORE UPDATE ON core_learning_mastery_profiles FOR EACH ROW
BEGIN
    DECLARE old_calculated_at TIMESTAMP(6);
    DECLARE parent_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_found = FALSE;
    IF NOT (NEW.customer_id <=> OLD.customer_id) OR NOT (NEW.user_id <=> OLD.user_id)
       OR NOT (NEW.framework_id <=> OLD.framework_id)
       OR NOT (NEW.node_definition_id <=> OLD.node_definition_id)
       OR NOT (NEW.basis_framework_version_id <=> OLD.basis_framework_version_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROFILE_MISMATCH';
    END IF;
    IF NOT EXISTS (
       SELECT 1 FROM core_learning_mastery_calculations calculation_row
        WHERE calculation_row.id = NEW.current_calculation_id
          AND calculation_row.customer_id = NEW.customer_id AND calculation_row.user_id = NEW.user_id
          AND calculation_row.framework_id = NEW.framework_id
          AND calculation_row.node_definition_id = NEW.node_definition_id
          AND calculation_row.basis_framework_version_id = NEW.basis_framework_version_id
          AND calculation_row.mastery_level_key = NEW.mastery_level_key
          AND calculation_row.mastery_score <=> NEW.mastery_score
          AND calculation_row.mastery_status_result = NEW.mastery_status
          AND calculation_row.calculated_at = NEW.calculated_at
          AND calculation_row.reassessment_due_at <=> NEW.reassessment_due_at
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROFILE_MISMATCH';
    END IF;
    SELECT calculated_at INTO old_calculated_at FROM core_learning_mastery_calculations
     WHERE id = OLD.current_calculation_id AND customer_id = OLD.customer_id
       AND user_id = OLD.user_id;
    IF parent_found = FALSE THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROFILE_MISMATCH';
    END IF;
    IF NEW.calculated_at < old_calculated_at
       OR (NEW.calculated_at = old_calculated_at
           AND NEW.current_calculation_id < OLD.current_calculation_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROFILE_STALE';
    END IF;
END;

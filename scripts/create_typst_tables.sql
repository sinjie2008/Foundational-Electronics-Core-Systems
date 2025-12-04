DROP TABLE IF EXISTS `typst_variables`;
DROP TABLE IF EXISTS `typst_series_preferences`;
DROP TABLE IF EXISTS `typst_templates`;

CREATE TABLE `typst_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `typst_content` MEDIUMTEXT,
  `is_global` TINYINT(1) DEFAULT 0,
  `series_id` INT DEFAULT NULL,
  `last_pdf_path` VARCHAR(255) DEFAULT NULL,
  `last_pdf_generated_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `typst_series_preferences` (
  `series_id` INT NOT NULL,
  `last_global_template_id` INT DEFAULT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`series_id`),
  CONSTRAINT `fk_series_pref_template` FOREIGN KEY (`last_global_template_id`) REFERENCES `typst_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `typst_variables` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `field_key` VARCHAR(255) NOT NULL,
  `field_type` VARCHAR(50) NOT NULL DEFAULT 'text',
  `field_value` TEXT,
  `is_global` TINYINT(1) DEFAULT 0,
  `series_id` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

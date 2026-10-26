CREATE TABLE IF NOT EXISTS `bd_app_i18n_setting` (
  `id` tinyint unsigned NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='国际化服务私有配置';

CREATE TABLE IF NOT EXISTS `bd_app_translation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `resource_type` varchar(40) NOT NULL,
  `resource_id` bigint unsigned NOT NULL,
  `field` varchar(40) NOT NULL,
  `locale` varchar(20) NOT NULL,
  `source_locale` varchar(20) NOT NULL,
  `source_hash` char(64) NOT NULL,
  `source_text` longtext NOT NULL,
  `translated_text` longtext NOT NULL,
  `origin` varchar(10) NOT NULL DEFAULT 'machine',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `error` varchar(255) NOT NULL DEFAULT '',
  `available_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_locale` (`resource_type`,`resource_id`,`field`,`locale`),
  KEY `worker` (`status`,`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容译文与翻译任务';

CREATE TABLE IF NOT EXISTS `bd_app_i18n_message` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `text` text NOT NULL,
  `source_hash` char(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'normal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_hash` (`source_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='可翻译的业务提示原文目录';

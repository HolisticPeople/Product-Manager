<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public-safe source identity for Product Manager's governed editorial gallery.
 *
 * This provider exposes only the exact product/field identity and ordered
 * attachment IDs. HP-Catalog owns attachment validation, review, eligibility,
 * delivery metadata, and the public presentation projection.
 */
final class HP_PM_Product_Editorial_Gallery_Source_Provider
{
    public const PROVIDER_ID = 'hp_product_manager_editorial_gallery_source';
    public const CAPABILITY = 'product_editorial_gallery_source';
    public const SCHEMA_VERSION = 'hp_product_editorial_gallery_source_v1';
    public const CONTRACT_VERSION = '1.0.0';
    public const FIELD_KEY = 'field_679f5a3cce9a1';
    public const FIELD_NAME = 'product_magazine_gallery';
    public const FIELD_GROUP = 'group_6062fd00d4314';
    public const BOOK_TYPE = 'book_type';
    public const MAX_ITEMS = 24;

    public static function register(): void
    {
        if (function_exists('add_action')) {
            add_action('hp_core_register_contract_providers', [self::class, 'register_provider']);
        }
    }

    public static function register_provider(): void
    {
        if (!function_exists('\HP_Core\register_contract_provider')) {
            return;
        }

        \HP_Core\register_contract_provider([
            'provider_id' => self::PROVIDER_ID,
            'plugin' => 'products-manager',
            'label' => 'Product Manager Editorial Gallery Source',
            'description' => 'Public-safe product and field provenance plus ordered editorial-gallery attachment IDs.',
            'contract_version' => self::CONTRACT_VERSION,
            'capabilities' => [self::CAPABILITY],
            'schema_versions' => [self::SCHEMA_VERSION],
            'rest_namespace' => '',
            'rest_base' => '',
            'payload_callback' => [self::class, 'payload'],
        ]);
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $publicContract
     * @return array<string,mixed>|\WP_Error
     */
    public static function payload(
        string $capability,
        array $context = [],
        array $publicContract = []
    ): array|\WP_Error {
        unset($publicContract);

        if ($capability !== self::CAPABILITY) {
            return self::error('unsupported_capability', 'The requested editorial-gallery source capability is unsupported.');
        }

        $consumer = isset($context['consumer']) && is_scalar($context['consumer'])
            ? sanitize_key((string) $context['consumer'])
            : '';
        if ($consumer !== 'hp-catalog') {
            return self::error('unauthorized_consumer', 'The editorial-gallery source is available only to HP-Catalog.');
        }

        $productId = isset($context['product_id']) ? absint($context['product_id']) : 0;
        if ($productId < 1) {
            return self::error('invalid_product_id', 'A positive product ID is required.');
        }
        if (!function_exists('get_post') || !function_exists('get_post_meta')) {
            return self::error('source_unavailable', 'WordPress product source APIs are unavailable.');
        }

        $fieldIdentityError = self::field_identity_error();
        if ($fieldIdentityError instanceof \WP_Error) {
            return $fieldIdentityError;
        }

        $post = get_post($productId);
        if (!is_object($post)
            || (string) ($post->post_type ?? '') !== 'product'
            || (string) ($post->post_status ?? '') !== 'publish'
        ) {
            return self::error('product_unavailable', 'The requested public product is unavailable.');
        }

        $rawProductType = sanitize_key((string) get_post_meta($productId, 'product_type_hp', true));
        if ($rawProductType !== self::BOOK_TYPE) {
            return self::error('wrong_product_type', 'The editorial-gallery source is available only for Book products.');
        }

        $attachmentIds = self::normalize_attachment_ids(
            get_post_meta($productId, self::FIELD_NAME, true)
        );
        if (is_wp_error($attachmentIds)) {
            return $attachmentIds;
        }

        $canonicalSource = [
            'schema_version' => self::SCHEMA_VERSION,
            'product' => [
                'id' => $productId,
                'post_type' => 'product',
                'product_type' => 'book',
                'status' => 'publish',
            ],
            'field' => [
                'field_key' => self::FIELD_KEY,
                'field_name' => self::FIELD_NAME,
                'group_key' => self::FIELD_GROUP,
                'storage' => 'acf_product_meta',
            ],
            'attachment_ids' => $attachmentIds,
        ];
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($canonicalSource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($canonicalSource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return self::error('fingerprint_failed', 'The editorial-gallery source fingerprint could not be generated.');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider_id' => self::PROVIDER_ID,
            'contract_version' => self::CONTRACT_VERSION,
            'product' => $canonicalSource['product'],
            'field' => $canonicalSource['field'],
            'attachment_ids' => $attachmentIds,
            'source_fingerprint' => 'sha256:' . hash('sha256', $encoded),
        ];
    }

    /**
     * @return list<int>|\WP_Error
     */
    public static function normalize_attachment_ids(mixed $raw): array|\WP_Error
    {
        if ($raw === '' || $raw === null || $raw === false || $raw === []) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return self::error('invalid_source_shape', 'The editorial-gallery source must be an ordered attachment-ID list.');
        }
        if (count($raw) > self::MAX_ITEMS) {
            return self::error('too_many_items', 'The editorial-gallery source exceeds the governed item limit.');
        }

        $normalized = [];
        $seen = [];
        foreach ($raw as $value) {
            if (is_int($value) && $value > 0) {
                $attachmentId = $value;
            } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
                $attachmentId = (int) $value;
                if ((string) $attachmentId !== $value) {
                    return self::error('invalid_attachment_id', 'The editorial-gallery source contains an invalid attachment ID.');
                }
            } else {
                return self::error('invalid_attachment_id', 'The editorial-gallery source contains an invalid attachment ID.');
            }

            if ($attachmentId < 1) {
                return self::error('invalid_attachment_id', 'The editorial-gallery source contains an invalid attachment ID.');
            }
            if (isset($seen[$attachmentId])) {
                return self::error('duplicate_attachment_id', 'The editorial-gallery source contains a duplicate attachment ID.');
            }

            $seen[$attachmentId] = true;
            $normalized[] = $attachmentId;
        }

        return $normalized;
    }

    private static function field_identity_error(): ?\WP_Error
    {
        if (!function_exists('acf_get_field')) {
            return self::error('field_registry_unavailable', 'The governed ACF field registry is unavailable.');
        }

        $field = acf_get_field(self::FIELD_KEY);
        if (!is_array($field)
            || (string) ($field['key'] ?? '') !== self::FIELD_KEY
            || (string) ($field['name'] ?? '') !== self::FIELD_NAME
            || (string) ($field['type'] ?? '') !== 'gallery'
        ) {
            return self::error('field_identity_mismatch', 'The governed editorial-gallery field identity does not match.');
        }

        $parent = $field['parent'] ?? '';
        if ((string) $parent === self::FIELD_GROUP) {
            return null;
        }
        if (!is_numeric($parent)) {
            return self::error('field_identity_mismatch', 'The governed editorial-gallery field group does not match.');
        }

        $group = false;
        if (function_exists('acf_get_raw_field_group')) {
            $group = acf_get_raw_field_group((int) $parent);
        } elseif (function_exists('acf_get_field_group')) {
            $group = acf_get_field_group((int) $parent);
        }
        if (!is_array($group) || (string) ($group['key'] ?? '') !== self::FIELD_GROUP) {
            return self::error('field_identity_mismatch', 'The governed editorial-gallery field group does not match.');
        }

        return null;
    }

    private static function error(string $code, string $message): \WP_Error
    {
        return new \WP_Error('hp_pm_editorial_gallery_' . $code, $message);
    }
}

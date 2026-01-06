# HP Product Manager MCP Tools

This document defines the schema for custom MCP tools that allow AI agents to manage WooCommerce products directly through the HP Products Manager plugin.

## Tool: hp-product-update-comprehensive

Updates a product with core WooCommerce data, ACF fields, and SEO metadata in a single call.

### Schema (JSON)

```json
{
  "name": "hp-product-update-comprehensive",
  "description": "Comprehensive product update including core fields, ACF, and Yoast SEO.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "id": { "type": "integer", "description": "The WooCommerce Product ID" },
      "changes": {
        "type": "object",
        "properties": {
          "name": { "type": "string" },
          "sku": { "type": "string" },
          "price": { "type": ["number", "string"] },
          "sale_price": { "type": ["number", "string"] },
          "status": { "enum": ["publish", "draft", "private", "pending"] },
          "visibility": { "enum": ["visible", "catalog", "search", "hidden"] },
          "short_description": { "type": "string" },
          "tax_status": { "enum": ["taxable", "shipping", "none"] },
          "tax_class": { "type": "string" },
          "sold_individually": { "type": "boolean" },
          "weight": { "type": ["number", "string"] },
          "length": { "type": ["number", "string"] },
          "width": { "type": ["number", "string"] },
          "height": { "type": ["number", "string"] },
          "cost": { "type": ["number", "string"] },
          "manage_stock": { "type": "boolean" },
          "stock_quantity": { "type": ["integer", "null"] },
          "backorders": { "enum": ["no", "notify", "yes"] },
          "brands": { "type": "array", "items": { "type": "string" }, "description": "Slugs of brands" },
          "categories": { "type": "array", "items": { "type": "string" }, "description": "Slugs of categories" },
          "tags": { "type": "array", "items": { "type": "string" }, "description": "Slugs of tags" },
          "upsell_ids": { "type": "array", "items": { "type": "integer" } },
          "crosssell_ids": { "type": "array", "items": { "type": "integer" } },
          "yoast_focuskw": { "type": "string" },
          "yoast_title": { "type": "string" },
          "yoast_metadesc": { "type": "string" },
          "serving_size": { "type": "string" },
          "servings_per_container": { "type": "string" },
          "serving_form_unit": { "type": "string" },
          "supplement_form": { "type": "string" },
          "bottle_size_eu": { "type": "string" },
          "bottle_size_units_eu": { "type": "string" },
          "bottle_size_usa": { "type": "string" },
          "bottle_size_units_usa": { "type": "string" },
          "ingredients": { "type": "string" },
          "ingredients_other": { "type": "string" },
          "potency": { "type": "string" },
          "potency_units": { "type": "string" },
          "sku_mfr": { "type": "string" },
          "manufacturer_acf": { "type": "string" },
          "country_of_manufacturer": { "type": "string" },
          "how_to_use": { "type": "string" },
          "cautions": { "type": "string" },
          "recommended_use": { "type": "string" },
          "community_tips": { "type": "string" },
          "body_systems_organs": { "type": "array", "items": { "type": "string" } },
          "traditional_function": { "type": "string" },
          "chinese_energy": { "type": "array", "items": { "type": "string" } },
          "ayurvedic_energy": { "type": "array", "items": { "type": "string" } },
          "supplement_type": { "type": "array", "items": { "type": "string" } },
          "expert_article": { "type": "string" },
          "video": { "type": "string" },
          "video_transcription": { "type": "string" },
          "slogan": { "type": "string" },
          "aka_product_name": { "type": "string" },
          "description_long": { "type": "string" },
          "product_type_hp": { "type": "string" },
          "site_catalog": { "type": "array", "items": { "type": "string" } }
        }
      }
    },
    "required": ["id", "changes"]
  }
}
```

## Tool: hp-product-seo-audit

Retrieves an SEO and readability audit for a specific product.

### Schema (JSON)

```json
{
  "name": "hp-product-seo-audit",
  "description": "Performs an SEO audit on a product including keyword checks and length analysis.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "id": { "type": "integer", "description": "The WooCommerce Product ID" }
    },
    "required": ["id"]
  }
}
```


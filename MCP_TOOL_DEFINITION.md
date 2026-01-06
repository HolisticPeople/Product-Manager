# MCP Tool Definition: hp-product-update-comprehensive

This document defines the new MCP tool that leverages the Product Manager plugin's expanded REST API.

## Tool Name
`hp-product-update-comprehensive`

## Description
Build or improve a WooCommerce product by updating standard WC fields and all 46 custom ACF fields (dosing, ingredients, safety, expert info, etc.) in a single operation.

## Parameters (JSON Schema)
```json
{
  "type": "object",
  "properties": {
    "id": { "type": "integer", "description": "The WooCommerce Product ID" },
    "changes": {
      "type": "object",
      "properties": {
        "name": { "type": "string" },
        "sku": { "type": "string" },
        "price": { "type": "string" },
        "sale_price": { "type": "string" },
        "status": { "type": "string", "enum": ["publish", "draft", "private", "pending"] },
        "visibility": { "type": "string", "enum": ["visible", "catalog", "search", "hidden"] },
        "manage_stock": { "type": "boolean" },
        "stock_quantity": { "type": "integer" },
        "backorders": { "type": "string", "enum": ["no", "notify", "yes"] },
        
        "serving_size": { "type": "string" },
        "servings_per_container": { "type": "string" },
        "serving_form_unit": { "type": "string" },
        "supplement_form": { "type": "string" },
        
        "ingredients": { "type": "string" },
        "ingredients_other": { "type": "string" },
        "potency": { "type": "string" },
        "potency_units": { "type": "string" },
        
        "how_to_use": { "type": "string" },
        "cautions": { "type": "string" },
        
        "body_systems_organs": { "type": "array", "items": { "type": "string" } },
        "site_catalog": { "type": "array", "items": { "type": "string" } }
      }
    }
  },
  "required": ["id", "changes"]
}
```

## Implementation Mapping
The tool should map to the following REST endpoint:
`POST /wp-json/hp-products-manager/v1/product/{id}/apply`

The payload should be wrapped in a `changes` key:
```json
{
  "changes": {
    "name": "New Name",
    "ingredients": "New Ingredients list..."
  }
}
```


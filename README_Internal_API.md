# NEOSidekick Internal API Reference

This document describes the internal HTTP API endpoints provided by the `NEOSidekick.AiAssistant` Neos package. These endpoints are consumed by the NEOSidekick LLM Agent platform to enable AI-based content editing agents.

## Quick Start

Test the API endpoints with these curl commands (replace with your domain and API key):

```bash
# 1. Get NodeType schema
curl -X GET "https://your-site.com/neosidekick/api/nodetype-schema" \
  -H "Authorization: Bearer your-api-key"

# 2. Get document list (for German content)
curl -G "https://your-site.com/neosidekick/api/document-nodes" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key"

# 3. Get node tree (use identifier from document list)
curl -G "https://your-site.com/neosidekick/api/node-tree" \
  --data-urlencode "nodeId=your-node-uuid" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key"
```

---

## Authentication

All API endpoints (except Backend Service) require Bearer token authentication:

```
Authorization: Bearer {apiKey}
```

The API key must match the configured value in `Settings.yaml`:

```yaml
NEOSidekick:
  AiAssistant:
    apikey: 'your-secret-api-key'
```

### Error Responses

**401 Unauthorized** - Missing or invalid authentication:

```json
{
  "error": "Unauthorized",
  "message": "Missing Authorization header"
}
```

```json
{
  "error": "Unauthorized",
  "message": "Invalid API key"
}
```

---

## Endpoints Overview

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/neosidekick/api/nodetype-schema` | GET | Get NodeType definitions for LLM agents |
| `/neosidekick/api/node-tree` | GET | Get node tree starting from a specific node |
| `/neosidekick/api/document-nodes` | GET | Get list of all document nodes (pages) |
| `/neosidekick/aiassistant/service/{action}` | GET/POST | Backend service for UI integration |

---

## 1. NodeType Schema API

Returns all NodeType definitions with their properties, childNodes, and constraints. Used by the NEOSidekick LLM Agent platform to understand the content structure.

### Endpoint

```
GET /neosidekick/api/nodetype-schema
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `includeAbstract` | bool | No | `false` | Include abstract NodeTypes |
| `filter` | string | No | `""` | Filter by NodeType prefix (e.g., `CodeQ.Site:`) |

### Example Request

```bash
curl -X GET "https://example.com/neosidekick/api/nodetype-schema?filter=CodeQ.Site:" \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"
```

### Response

```json
{
  "generatedAt": "2025-12-31T10:00:00+00:00",
  "nodeTypes": [
    {
      "name": "CodeQ.Site:Content.Text.Block",
      "isContentCollection": false,
      "properties": {
        "text": {
          "type": "string",
          "defaultValue": null,
          "ui": {
            "label": "Text",
            "inline": {
              "editorOptions": {
                "formatting": {
                  "strong": true,
                  "em": true,
                  "p": true
                }
              }
            }
          },
          "validation": {
            "Neos.Neos/Validation/NotEmptyValidator": []
          }
        }
      },
      "childNodes": {},
      "constraints": {
        "nodeTypes": {
          "*": false
        }
      }
    }
  ]
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `generatedAt` | string | ISO 8601 timestamp |
| `nodeTypes` | array | List of NodeType definitions |
| `nodeTypes[].name` | string | Full NodeType name |
| `nodeTypes[].isContentCollection` | bool | Whether it extends `Neos.Neos:ContentCollection` |
| `nodeTypes[].properties` | object | Property definitions with type, ui, validation |
| `nodeTypes[].childNodes` | object | Named childNode configurations |
| `nodeTypes[].constraints` | object | NodeType constraints |

---

## 2. Node Tree API

Returns the complete node tree starting from a specific node. Used to generate JSX representations of page content for LLM agents.

### Endpoint

```
GET /neosidekick/api/node-tree
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `nodeId` | string | **Yes** | - | Node identifier (UUID) to start from |
| `workspace` | string | No | `live` | Workspace name |
| `dimensions` | string | No | `{}` | JSON-encoded dimensions |

### Example Request

```bash
# Note: dimensions must be URL-encoded
curl -X GET "https://example.com/neosidekick/api/node-tree?nodeId=abc-123&workspace=live&dimensions=%7B%22language%22%3A%5B%22de%22%5D%7D" \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# Using --data-urlencode for automatic encoding
curl -G "https://example.com/neosidekick/api/node-tree" \
  --data-urlencode "nodeId=abc-123" \
  --data-urlencode "workspace=live" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"
```

### Response

```json
{
  "generatedAt": "2025-12-31T10:00:00+00:00",
  "rootNode": {
    "id": "uuid-123",
    "nodeType": "CodeQ.Site:Document.AbstractPage",
    "properties": {
      "title": "Welcome",
      "heroTitle": "Hello World"
    },
    "children": {
      "main": {
        "allowedTypes": ["CodeQ.Site:Constraint.Content.Section"],
        "nodes": [
          {
            "id": "uuid-456",
            "nodeType": "CodeQ.Site:Content.Text.Block",
            "properties": {
              "text": "<p>Content here</p>"
            },
            "children": {}
          }
        ]
      }
    }
  }
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `generatedAt` | string | ISO 8601 timestamp |
| `rootNode` | object | The root node and its descendants |
| `rootNode.id` | string | Node UUID |
| `rootNode.nodeType` | string | Full NodeType name |
| `rootNode.properties` | object | Node properties (filtered, serialized) |
| `rootNode.children` | object | Child slots with `allowedTypes` and `nodes` |

### Children Model

The children object uses a unified model:

- **`_self`** slot: When the node IS a ContentCollection (content placed directly inside)
- **Named slots**: For configured childNodes (e.g., `main`, `sidebar`, `footer`)
- **Empty object**: For leaf nodes without children

### Error Response

**404 Not Found** - Node not found:

```json
{
  "error": "Not Found",
  "message": "Node with identifier \"uuid\" not found in workspace \"live\""
}
```

---

## 3. Document Node List API

Returns a list of all document nodes (pages) for a given workspace and dimension. Used for site navigation and page discovery by LLM agents.

### Endpoint

```
GET /neosidekick/api/document-nodes
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `workspace` | string | No | `live` | Workspace name |
| `dimensions` | string | No | `{}` | JSON-encoded dimensions |
| `site` | string | No | (first site) | Site node name |
| `nodeTypeFilter` | string | No | `Neos.Neos:Document` | Filter by NodeType |
| `depth` | int | No | `-1` | Max traversal depth (-1 = unlimited) |

### Example Request

```bash
# Note: dimensions must be URL-encoded
curl -X GET "https://example.com/neosidekick/api/document-nodes?workspace=live&dimensions=%7B%22language%22%3A%5B%22de%22%5D%7D" \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# Using --data-urlencode for automatic encoding
curl -G "https://example.com/neosidekick/api/document-nodes" \
  --data-urlencode "workspace=live" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# With explicit site name
curl -G "https://example.com/neosidekick/api/document-nodes" \
  --data-urlencode "site=my-site" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"
```

> **Note:** For multi-language Neos sites, the `dimensions` parameter is required to resolve content correctly. Without dimensions, the API may not find any sites.

### Response

```json
{
  "generatedAt": "2025-12-31T10:00:00+00:00",
  "workspace": "live",
  "dimensions": {
    "language": ["de"]
  },
  "site": {
    "name": "my-site",
    "nodeType": "Neos.Neos:Site",
    "identifier": "site-uuid"
  },
  "documents": [
    {
      "identifier": "uuid-1",
      "nodeType": "CodeQ.Site:Document.AbstractPage",
      "path": "/sites/my-site",
      "depth": 0,
      "title": "Homepage",
      "uriPath": "/",
      "properties": {
        "title": "Homepage",
        "metaDescription": "Welcome to our website"
      },
      "childDocumentCount": 5,
      "isHidden": false,
      "isHiddenInMenu": false
    },
    {
      "identifier": "uuid-2",
      "nodeType": "CodeQ.Site:Document.AbstractPage",
      "path": "/sites/my-site/about",
      "depth": 1,
      "title": "About Us",
      "uriPath": "/about",
      "properties": {
        "title": "About Us",
        "metaDescription": "Learn more about our company"
      },
      "childDocumentCount": 2,
      "isHidden": false,
      "isHiddenInMenu": false
    }
  ],
  "documentCount": 42
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `generatedAt` | string | ISO 8601 timestamp |
| `workspace` | string | Queried workspace name |
| `dimensions` | object | Dimension values used |
| `site` | object | Site information |
| `documents` | array | List of document nodes |
| `documentCount` | int | Total documents returned |

#### Document Object

| Field | Type | Description |
|-------|------|-------------|
| `identifier` | string | Node UUID (use for API calls) |
| `nodeType` | string | Full NodeType name |
| `path` | string | Content repository path |
| `depth` | int | Depth in tree (0 = site root) |
| `title` | string | Document title |
| `uriPath` | string | Public URL path |
| `properties` | object | Selected properties (configurable) |
| `childDocumentCount` | int | Number of child pages |
| `isHidden` | bool | Node visibility |
| `isHiddenInMenu` | bool | Hidden in navigation |

### Configuration

Configure which properties to include in `Settings.yaml`:

```yaml
NEOSidekick:
  AiAssistant:
    documentNodeList:
      includedProperties:
        - 'title'
        - 'metaDescription'
        - 'uriPathSegment'
```

---

## 4. Backend Service API

Internal service endpoint for the Neos backend UI integration. Used by the NEOSidekick backend module.

### Endpoint

```
GET/POST /neosidekick/aiassistant/service/{action}
```

### Available Actions

This endpoint supports various actions for the backend UI. Refer to `BackendServiceController.php` for specific action implementations.

---

## Common Patterns

### Dimension Format

Dimensions are passed as URL-encoded JSON strings:

```
# URL-encoded format (use in actual requests)
?dimensions=%7B%22language%22%3A%5B%22de%22%5D%7D

# Decoded JSON format (for reference)
?dimensions={"language":["de"]}
?dimensions={"language":["en"],"country":["us"]}
```

**Important:** For multi-language Neos installations, dimensions are required to resolve content correctly. The dimension structure must match your Neos content dimension configuration.

Common dimension formats:
- Single language: `{"language":["de"]}`
- Multiple fallbacks: `{"language":["de","en"]}`
- Multiple dimensions: `{"language":["de"],"country":["at"]}`

### Workspace Names

Common workspace patterns:
- `live` - Published content
- `user-{username}` - User workspace (e.g., `user-admin`)

### Error Handling

All endpoints return consistent error responses:

```json
{
  "error": "Error Type",
  "message": "Detailed error message"
}
```

HTTP Status Codes:
- `200` - Success
- `400` - Bad Request (invalid parameters)
- `401` - Unauthorized (authentication failed)
- `404` - Not Found (resource not found)
- `500` - Internal Server Error

---

## Architecture

These API endpoints follow a split architecture pattern:

```
┌──────────────────────────────────┐
│   NEOSidekick LLM Agent Platform │
│          (Laravel)               │
├──────────────────────────────────┤
│  - HTTP Clients                  │
│  - Data Transformation           │
│  - LLM Integration               │
└──────────────────────────────────┘
              │
              │ HTTP GET
              │ Authorization: Bearer {apiKey}
              ▼
┌──────────────────────────────────┐
│      Neos CMS                    │
│      (NEOSidekick.AiAssistant)   │
├──────────────────────────────────┤
│  - Raw Data Extraction           │
│  - Minimal Transformation        │
│  - JSON Responses                │
└──────────────────────────────────┘
```

This architecture ensures:
- **Minimal Neos package footprint** - Only data extraction
- **Centralized transformation** - All formatting in Laravel
- **Easy updates** - No package updates for format changes

---

## Security Considerations

1. **API Key**: Store securely, never commit to version control. Configure in `Settings.yaml` under `NEOSidekick.AiAssistant.apikey`
2. **HTTPS**: Always use HTTPS in production
3. **Workspace Access**: API provides data for any workspace - consider access control
4. **Hidden Content**: Hidden nodes may be included - handle appropriately
5. **Rate Limiting**: Consider implementing rate limiting for large sites
6. **Policy Configuration**: The API controllers are granted public access via `Policy.yaml` with the privilege `NEOSidekick.AiAssistant:PublicApi`. Authentication is handled via Bearer token in the controller, not via Flow's security framework

---

## Related Files

### Controllers

API controllers are placed directly in the Controller namespace (not in a subpackage) due to Flow routing requirements:

- `Classes/Controller/NodeTypeSchemaApiController.php` - NodeType schema endpoint
- `Classes/Controller/NodeTreeSchemaApiController.php` - Node tree endpoint
- `Classes/Controller/DocumentNodeListApiController.php` - Document list endpoint
- `Classes/Controller/BackendServiceController.php` - Backend UI service

### Services

Data extraction services that provide raw data to the controllers:

- `Classes/Service/NodeTypeSchemaExtractor.php` - Extracts NodeType definitions
- `Classes/Service/NodeTreeExtractor.php` - Traverses and extracts node trees
- `Classes/Service/DocumentNodeListExtractor.php` - Extracts document node lists

### Configuration

- `Configuration/Routes.yaml` - API route definitions
- `Configuration/Policy.yaml` - Security policy (grants public access to API controllers)
- `Configuration/Settings.Internal.yaml` - Authentication pattern configuration


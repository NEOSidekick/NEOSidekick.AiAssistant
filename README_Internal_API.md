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

# 4. Search nodes (grep-like search across all properties)
curl -G "https://your-site.com/neosidekick/api/search-nodes" \
  --data-urlencode "query=search term" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key"

# 5. Search media assets (find images by title, filename, or caption)
curl -G "https://your-site.com/neosidekick/api/search-media-assets" \
  --data-urlencode "query=logo" \
  -H "Authorization: Bearer your-api-key"

# 6. Apply patches (create, update, move, delete nodes)
curl -X POST "https://your-site.com/neosidekick/api/apply-patches" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "workspace": "live",
    "dimensions": {"language": ["de"]},
    "dryRun": true,
    "patches": [
      {"operation": "updateNode", "nodeId": "your-node-uuid", "properties": {"title": "New Title"}}
    ]
  }'
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
| `/neosidekick/api/search-nodes` | GET | Search across all node properties (grep-like) |
| `/neosidekick/api/search-media-assets` | GET | Search media assets by title, filename, or caption |
| `/neosidekick/api/apply-patches` | POST | Apply atomic patches (create, update, move, delete nodes) |
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

## 4. Search Nodes API

Performs grep-like search across all node properties for a given workspace and dimension. Used by LLM agents to find specific content within the site.

### Endpoint

```
GET /neosidekick/api/search-nodes
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | **Yes** | - | Search term (case-insensitive) |
| `workspace` | string | No | `live` | Workspace name |
| `dimensions` | string | No | `{}` | JSON-encoded dimensions |
| `nodeTypeFilter` | string | No | `Neos.Neos:Node` | Filter by NodeType (e.g., `Neos.Neos:Content`) |
| `pathStartingPoint` | string | No | (all paths) | Limit search to nodes under this path |

### Example Request

```bash
# Basic search
curl -G "https://example.com/neosidekick/api/search-nodes" \
  --data-urlencode "query=welcome" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# Search with NodeType filter (only content nodes)
curl -G "https://example.com/neosidekick/api/search-nodes" \
  --data-urlencode "query=hello world" \
  --data-urlencode "nodeTypeFilter=Neos.Neos:Content" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# Search within a specific path
curl -G "https://example.com/neosidekick/api/search-nodes" \
  --data-urlencode "query=product" \
  --data-urlencode "pathStartingPoint=/sites/my-site/products" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"
```

### Response

```json
{
  "generatedAt": "2025-12-31T10:00:00+00:00",
  "workspace": "live",
  "dimensions": {
    "language": ["de"]
  },
  "query": "welcome",
  "nodeTypeFilter": null,
  "pathStartingPoint": null,
  "results": [
    {
      "identifier": "uuid-1",
      "nodeType": "CodeQ.Site:Content.Text.Block",
      "path": "/sites/my-site/main/text-1",
      "depth": 4,
      "properties": {
        "title": "Welcome Message",
        "text": "<p>Welcome to our website</p>"
      },
      "isHidden": false,
      "parentDocumentIdentifier": "uuid-doc-1",
      "parentDocumentPath": "/sites/my-site",
      "parentDocumentTitle": "Homepage"
    },
    {
      "identifier": "uuid-2",
      "nodeType": "CodeQ.Site:Document.Page",
      "path": "/sites/my-site/welcome",
      "depth": 2,
      "properties": {
        "title": "Welcome Page"
      },
      "isHidden": false
    }
  ],
  "resultCount": 2
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `generatedAt` | string | ISO 8601 timestamp |
| `workspace` | string | Queried workspace name |
| `dimensions` | object | Dimension values used |
| `query` | string | The search term used |
| `nodeTypeFilter` | string\|null | NodeType filter applied |
| `pathStartingPoint` | string\|null | Path restriction applied |
| `results` | array | List of matching nodes |
| `resultCount` | int | Total results returned |

#### Result Object

| Field | Type | Description |
|-------|------|-------------|
| `identifier` | string | Node UUID |
| `nodeType` | string | Full NodeType name |
| `path` | string | Content repository path |
| `depth` | int | Depth in node tree |
| `properties` | object | Selected properties (configurable) |
| `isHidden` | bool | Node visibility |
| `parentDocumentIdentifier` | string | Parent page UUID (for content nodes) |
| `parentDocumentPath` | string | Parent page path (for content nodes) |
| `parentDocumentTitle` | string | Parent page title (for content nodes) |

### Configuration

Configure which properties to include in search results in `Settings.yaml`:

```yaml
NEOSidekick:
  AiAssistant:
    searchNodes:
      includedProperties:
        - 'title'
        - 'text'
        - 'headline'
        - 'metaDescription'
```

### Error Response

**400 Bad Request** - Missing required query parameter:

```json
{
  "error": "Bad Request",
  "message": "The \"query\" parameter is required and cannot be empty"
}
```

---

## 5. Search Media Assets API

Search for media assets (images, files) in the Neos Media library. Used by LLM agents to find appropriate images for content creation.

### Endpoint

```
GET /neosidekick/api/search-media-assets
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | **Yes** | - | Search term (searches title, filename, caption) |
| `mediaType` | string | No | `image/*` | Filter by media type (e.g., `image/*`, `application/pdf`) |
| `limit` | int | No | 10 | Max results to return (1-50) |

### Example Request

```bash
# Basic search for images
curl -G "https://example.com/neosidekick/api/search-media-assets" \
  --data-urlencode "query=logo" \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# Search with media type filter
curl -G "https://example.com/neosidekick/api/search-media-assets" \
  --data-urlencode "query=document" \
  --data-urlencode "mediaType=application/pdf" \
  --data-urlencode "limit=5" \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"
```

### Response

```json
{
  "generatedAt": "2026-01-01T10:00:00+00:00",
  "query": "logo",
  "mediaType": "image/*",
  "assets": [
    {
      "identifier": "edad3d53-f4eb-405b-a8b9-ac8c0094784c",
      "filename": "AI Sidekick Logo.png",
      "title": "NEOSidekick Logo",
      "caption": "The official NEOSidekick AI assistant logo",
      "mediaType": "image/png",
      "tags": ["logo", "branding"]
    },
    {
      "identifier": "abc12345-1234-5678-9abc-def012345678",
      "filename": "company-logo-dark.svg",
      "title": "Company Logo (Dark)",
      "caption": "",
      "mediaType": "image/svg+xml",
      "tags": ["logo"]
    }
  ],
  "totalCount": 15
}
```

### Response Fields

| Field | Type | Description |
|-------|------|-------------|
| `generatedAt` | string | ISO 8601 timestamp |
| `query` | string | The search term used |
| `mediaType` | string | Media type filter applied |
| `assets` | array | List of matching assets |
| `totalCount` | int | Total matching assets (may exceed `limit`) |

#### Asset Object

| Field | Type | Description |
|-------|------|-------------|
| `identifier` | string | Asset UUID (use in `image` property values) |
| `filename` | string | Original file name |
| `title` | string | Editorial title (may be empty) |
| `caption` | string | Description/alt text (may be empty) |
| `mediaType` | string | MIME type (e.g., `image/png`) |
| `tags` | array | Tag labels for categorization |

### Usage in Patches

Use the asset `identifier` when setting image properties:

```json
{
  "operation": "updateNode",
  "nodeId": "node-uuid",
  "properties": {
    "image": {
      "identifier": "edad3d53-f4eb-405b-a8b9-ac8c0094784c",
      "filename": "AI Sidekick Logo.png",
      "mediaType": "image/png"
    }
  }
}
```

### Error Response

**400 Bad Request** - Missing required query parameter:

```json
{
  "error": "Bad Request",
  "message": "The \"query\" parameter is required and cannot be empty"
}
```

---

## 6. Apply Patches API

Apply atomic patches to the content repository. Supports creating, updating, moving, and deleting nodes with transaction-based rollback and dry-run support.

### Endpoint

```
POST /neosidekick/api/apply-patches
```

### Request Body

```json
{
  "workspace": "user-admin",
  "dimensions": {"language": ["de"]},
  "dryRun": false,
  "patches": [
    {
      "operation": "createNode",
      "parentNodeId": "uuid-parent",
      "nodeType": "CodeQ.Site:Content.Text",
      "position": "into",
      "properties": {"text": "<p>Hello</p>"}
    },
    {
      "operation": "updateNode",
      "nodeId": "uuid-123",
      "properties": {"title": "New Title"}
    },
    {
      "operation": "moveNode",
      "nodeId": "uuid-456",
      "targetNodeId": "uuid-789",
      "position": "after"
    },
    {
      "operation": "deleteNode",
      "nodeId": "uuid-to-delete"
    }
  ]
}
```

### Request Fields

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `workspace` | string | No | `live` | Workspace name |
| `dimensions` | object | No | `{}` | Content dimensions |
| `dryRun` | bool | No | `false` | Validate without persisting changes |
| `patches` | array | **Yes** | - | Array of patch operations |

### Patch Operations

#### createNode

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `operation` | string | **Yes** | - | Must be `createNode` |
| `parentNodeId` | string | **Yes** | - | UUID of parent/reference node |
| `nodeType` | string | **Yes** | - | Full NodeType name |
| `position` | string | No | `into` | `into`, `before`, or `after` |
| `properties` | object | No | `{}` | Initial property values |

#### updateNode

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `operation` | string | **Yes** | - | Must be `updateNode` |
| `nodeId` | string | **Yes** | - | UUID of node to update |
| `properties` | object | **Yes** | - | Properties to set |

#### moveNode

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `operation` | string | **Yes** | - | Must be `moveNode` |
| `nodeId` | string | **Yes** | - | UUID of node to move |
| `targetNodeId` | string | **Yes** | - | UUID of target/reference node |
| `position` | string | No | `into` | `into`, `before`, or `after` |

#### deleteNode

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `operation` | string | **Yes** | - | Must be `deleteNode` |
| `nodeId` | string | **Yes** | - | UUID of node to delete |

### Example Request

```bash
curl -X POST "https://example.com/neosidekick/api/apply-patches" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "workspace": "user-admin",
    "dimensions": {"language": ["de"]},
    "dryRun": false,
    "patches": [
      {
        "operation": "updateNode",
        "nodeId": "abc-123-def",
        "properties": {
          "title": "New Title",
          "text": "<p>Updated content</p>"
        }
      },
      {
        "operation": "createNode",
        "parentNodeId": "parent-uuid",
        "nodeType": "CodeQ.Site:Content.Text",
        "position": "into",
        "properties": {
          "text": "<p>New paragraph</p>"
        }
      }
    ]
  }'
```

### Success Response (200)

```json
{
  "success": true,
  "dryRun": false,
  "results": [
    {"index": 0, "operation": "updateNode", "nodeId": "abc-123-def"},
    {
      "index": 1,
      "operation": "createNode",
      "nodeId": "new-uuid-created",
      "createdNodes": [
        {
          "nodeId": "new-uuid-created",
          "nodeType": "CodeQ.Site:Document.Page",
          "nodeName": "page-abc12345",
          "properties": {"title": "New Page", "uriPathSegment": "new-page"},
          "depth": 0
        },
        {
          "nodeId": "main-collection-uuid",
          "nodeType": "Neos.Neos:ContentCollection",
          "nodeName": "main",
          "properties": {},
          "depth": 1
        }
      ]
    }
  ]
}
```

#### Extended createNode Response

For `createNode` operations, the response includes a `createdNodes` array with details about all nodes that were created:

| Field | Type | Description |
|-------|------|-------------|
| `nodeId` | string | Node UUID |
| `nodeType` | string | Full NodeType name |
| `nodeName` | string | Node name (path segment) |
| `properties` | object | Node properties (filtered, serialized) |
| `depth` | int | Depth relative to main created node (0 = main node) |

This includes:
- The main node that was explicitly created
- Auto-created child nodes (fixed children configured in NodeType's `childNodes`)
- Nodes created by NodeTemplates (if configured in `options.template`)

The MCP tool formats this as JSX matching the `getDocumentContent` tool output:

```
✓ All patches applied successfully

  [0] createNode: new-uuid-created

      Created structure:
      ```tsx
      <CodeQ_Site__Document_Page id="new-uuid-created" title="New Page" uriPathSegment="new-page">
        <Neos_Neos__ContentCollection id="main-collection-uuid" />
      </CodeQ_Site__Document_Page>
      ```

Total: 1 operations applied
```

### Failure Response (422)

When a patch fails, all changes are rolled back:

```json
{
  "success": false,
  "dryRun": false,
  "error": {
    "message": "Property 'invalidProp' is not declared in NodeType",
    "patchIndex": 1,
    "operation": "updateNode",
    "nodeId": "uuid-123"
  },
  "rollbackPerformed": true
}
```

### Dry-Run Mode

When `dryRun: true`, all patches are validated and executed within a transaction, but the transaction is rolled back regardless of success. This allows you to validate patches without making changes:

```bash
curl -X POST "https://example.com/neosidekick/api/apply-patches" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "workspace": "user-admin",
    "dryRun": true,
    "patches": [...]
  }'
```

### Transaction Semantics

- All patches are executed within a single database transaction
- If any patch fails, all previous changes are rolled back
- Patches are validated before execution using `Flowpack.NodeTemplates` PropertiesProcessor
- NodeTemplates configured in `options.template` are automatically applied after `createNode`

### Workspace Limitations

**Important:** Personal workspaces like `user-admin` require a logged-in Neos backend user. This API uses Bearer token authentication which grants access to the API endpoint, but does NOT authenticate as a Neos backend user.

### Error Response

**400 Bad Request** - Invalid request structure:

```json
{
  "error": "Bad Request",
  "message": "Missing required field \"patches\""
}
```

**401 Unauthorized** - Authentication failed:

```json
{
  "error": "Unauthorized",
  "message": "Invalid API key"
}
```

**422 Unprocessable Entity** - Patch validation or execution failed (see failure response above)

---

## 7. Backend Service API

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
              │ HTTP GET/POST
              │ Authorization: Bearer {apiKey}
              ▼
┌──────────────────────────────────┐
│      Neos CMS                    │
│      (NEOSidekick.AiAssistant)   │
├──────────────────────────────────┤
│  - Raw Data Extraction           │
│  - Atomic Patch Operations       │
│  - Transaction Management        │
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
- `Classes/Controller/SearchNodesApiController.php` - Search nodes endpoint
- `Classes/Controller/SearchMediaAssetsApiController.php` - Search media assets endpoint
- `Classes/Controller/ApplyPatchesApiController.php` - Apply patches endpoint
- `Classes/Controller/BackendServiceController.php` - Backend UI service

### Services

Data extraction services that provide raw data to the controllers:

- `Classes/Service/NodeTypeSchemaExtractor.php` - Extracts NodeType definitions
- `Classes/Service/NodeTreeExtractor.php` - Traverses and extracts node trees
- `Classes/Service/DocumentNodeListExtractor.php` - Extracts document node lists
- `Classes/Service/SearchNodesExtractor.php` - Searches nodes by property values
- `Classes/Service/MediaAssetSearchService.php` - Searches media assets by title, filename, caption
- `Classes/Service/NodePatchService.php` - Applies atomic patches with transaction support
- `Classes/Service/PatchValidator.php` - Validates patches using NodeTemplates PropertiesProcessor

### Configuration

- `Configuration/Routes.yaml` - API route definitions
- `Configuration/Policy.yaml` - Security policy (grants public access to API controllers)
- `Configuration/Settings.Internal.yaml` - Authentication pattern configuration


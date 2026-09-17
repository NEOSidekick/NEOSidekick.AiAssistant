# NEOSidekick Internal API Reference

This document describes the internal HTTP API endpoints provided by the `NEOSidekick.AiAssistant` Neos package. These endpoints are consumed by the NEOSidekick LLM Agent platform to enable AI-based content editing agents.

## Quick Start

Test the API endpoints with these curl commands (replace `your-site.com` with your
domain and `your-api-key` with a valid **JWT Bearer token** — see
[Authentication](#authentication); the static `apikey` setting is not accepted here):

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

# 6. Upload media asset from URL
curl -X POST "https://your-site.com/neosidekick/api/upload-media-asset" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com/images/hero.jpg",
    "title": "Homepage Hero",
    "caption": "Hero image for homepage"
  }'

# 7. Apply patches (create, update, move, delete nodes)
curl -X POST "https://your-site.com/neosidekick/api/apply-patches" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "dimensions": {"language": ["de"]},
    "patches": [
      {"operation": "updateNode", "nodeId": "your-node-uuid", "properties": {"title": "New Title"}}
    ]
  }'
```

---

## Authentication

Most API endpoints (see [Endpoints Overview](#endpoints-overview) for the exceptions)
are protected by a Flow authentication provider (`NEOSidekick.AiAssistant:JwtApi`) and
require a **JSON Web Token** as a Bearer token:

```http
Authorization: Bearer {jwt}
```

> **Note:** `{jwt}` is **not** the static `NEOSidekick.AiAssistant.apikey` setting.
> The `apikey` setting is the NEOSidekick platform *license key* used by the Neos UI
> plugin (chat sidebar, inline editors) to talk to `api.neosidekick.com`. It is never
> accepted as a Bearer token on these API endpoints. In the `curl` examples in this
> document, `your-api-key` is a placeholder for the JWT described here.

### How the token is issued

The JWT is minted by `AgentTokenService` from an **authenticated Neos backend
session** and signed with **RS256** using this installation's own signing key; the
header carries a `kid` naming that key. Its claims include `sub` (the backend account
identifier), `user_id`, `account_id` and a real `exp` — the token is valid for one hour
(`AgentTokenService::ACCESS_TOKEN_LIFETIME = 3600`) and is renewed through the refresh
endpoint before it runs out.

Tokens minted by older releases carry **no** `kid` and are HS256-signed with Flow's
`HashService` encryption key; only those are bound to the Neos backend session they were
minted from. A `kid`-carrying token is never verified with HS256, and an unknown `kid` is
rejected outright.

An external client never mints the token itself. It is obtained through the agent
authorization flow: an editor consents in the Neos backend
(`/neosidekick/agent/request-authorization` → `/neosidekick/agent/do-authorize`), and
Neos forwards the freshly minted JWT to the NEOSidekick platform's OAuth callback,
keyed by `state`. The platform then sends that JWT as the Bearer token on every
subsequent API call.

Because the JWT resolves to a real `Neos.Neos:Backend` account, write operations
(`apply-patches`) act **as that user** and target their personal workspace.

### Error Responses

**401 Unauthorized** - Missing or invalid JWT:

```json
{
  "error": "Unauthorized",
  "message": "Valid JWT Bearer token required",
  "errorCode": "NEOS_JWT_REJECTED"
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
| `/neosidekick/api/upload-media-asset` | POST | Upload media asset from remote URL |
| `/neosidekick/api/apply-patches` | POST | Apply atomic patches (create, update, move, delete nodes) |
| `/neosidekick/api/whoami` | GET | Return the identity the Bearer token resolves to |
| `/neosidekick/api/getpreview` | GET | Return a signed, short-lived preview URL for a document node |
| `/neosidekick/api/agentic/refresh-token` | POST | Exchange an opaque refresh token for a fresh JWT (**anonymous**) |
| `/neosidekick/api/agentic/revoke-refresh-token` | POST | End a refresh-token family (**anonymous**) |
| `/neosidekick/api/agentic/embed-token` | POST | Mint an embed token for the chat iframe (**Neos backend session**) |
| `/neosidekick/aiassistant/service/{action}` | GET/POST | Backend service for UI integration |

Not every endpoint sits behind `NEOSidekick.AiAssistant:JwtApi`. The two `agentic/*`
refresh endpoints are granted to `Neos.Flow:Everybody` in `Policy.yaml` — the opaque
refresh token itself is the credential — and `agentic/embed-token` is reachable only
from an authenticated Neos backend session (`Neos.Neos:Backend` request pattern plus
the `NEOSidekick.AiAssistant:CanUse` privilege target). All remaining rows above,
except Backend Service, require the JWT Bearer token.

---

## 1. NodeType Schema API

Returns all NodeType definitions with their properties, childNodes, and constraints. Used by the NEOSidekick LLM Agent platform to understand the content structure.

### Endpoint

```http
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

```http
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

```http
GET /neosidekick/api/document-nodes
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `workspace` | string | No | `live` | Workspace name |
| `dimensions` | string | No | `{}` | JSON-encoded dimensions |
| `site` | string | No | (site whose Domain record matches the request host, else Neos's default site) | Site node name; an unknown name, or a value that is not a site node name, answers `400` listing the available ones |
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
  "availableSites": [
    {"nodeName": "my-site", "name": "My Site"},
    {"nodeName": "academy", "name": "Academy"}
  ],
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
| `availableSites` | array | Every site of the installation as `{nodeName, name}`, in tree order |
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

Performs grep-like search across all node properties for a given workspace and dimension. It also supports direct lookup by node identifier (UUID). Used by LLM agents to find specific content within the site.

### Endpoint

```http
GET /neosidekick/api/search-nodes
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | No | `""` | Search term (case-insensitive) or exact node identifier (UUID). Empty or `*` returns all document nodes |
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

# Search by node identifier (UUID)
curl -G "https://example.com/neosidekick/api/search-nodes" \
  --data-urlencode "query=c8ce98d5-adb2-4bce-8397-9b00bfbae4fc" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# Return all document nodes (empty query)
curl -G "https://example.com/neosidekick/api/search-nodes" \
  --data-urlencode "query=" \
  --data-urlencode 'dimensions={"language":["de"]}' \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# Return all document nodes (wildcard query)
curl -G "https://example.com/neosidekick/api/search-nodes" \
  --data-urlencode "query=*" \
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

If `query` is empty or `*`, this endpoint returns the same document list response model as `/neosidekick/api/document-nodes` (including `site`, `documents`, and `documentCount`). In this mode, the result is always all document nodes for the given workspace/dimensions.

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

---

## 5. Search Media Assets API

Search for media assets (images, files) in the Neos Media library. Used by LLM agents to find appropriate images for content creation.

### Endpoint

```http
GET /neosidekick/api/search-media-assets
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `query` | string | No | `""` | Search term (searches title, filename, caption). Empty or `*` returns all assets |
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

# Return all assets (empty query)
curl -G "https://example.com/neosidekick/api/search-media-assets" \
  --data-urlencode "query=" \
  -H "Authorization: Bearer your-api-key" \
  -H "Accept: application/json"

# Return all assets (wildcard query)
curl -G "https://example.com/neosidekick/api/search-media-assets" \
  --data-urlencode "query=*" \
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
      "previewUrl": "https://example.com/_Resources/Persistent/1/2/3/AI-Sidekick-Logo.png",
      "tags": ["logo", "branding"]
    },
    {
      "identifier": "abc12345-1234-5678-9abc-def012345678",
      "filename": "company-logo-dark.svg",
      "title": "Company Logo (Dark)",
      "caption": "",
      "mediaType": "image/svg+xml",
      "previewUrl": "https://example.com/_Resources/Persistent/a/b/c/company-logo-dark.svg",
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
| `previewUrl` | string | Public URL to preview/download the asset |
| `tags` | array | Tag labels for categorization |

### Usage in Patches

When setting image/asset properties, you can use **either format**:

#### Preferred: Just the identifier string (simpler and faster)
```json
{
  "operation": "updateNode",
  "nodeId": "node-uuid",
  "properties": {
    "image": "edad3d53-f4eb-405b-a8b9-ac8c0094784c"
  }
}
```

#### Also supported: Asset object format (matches search results)
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

The API automatically extracts the `identifier` from asset objects. This allows you to use the asset data from search results directly.

---

## 6. Upload Media Asset API

Upload a remote file into the Neos media library using a URL. This API uses Neos' internal `uploadAction` naming convention (as used in `Neos.Media.Browser`).

### Endpoint

```http
POST /neosidekick/api/upload-media-asset
```

### Request Body

```json
{
  "url": "https://example.com/images/hero.jpg",
  "title": "Homepage Hero",
  "caption": "Hero image for homepage"
}
```

### Request Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `url` | string | **Yes** | Source URL (`http`/`https`) of the file to import |
| `title` | string | No | Optional asset title |
| `caption` | string | No | Optional asset caption |

### Example Request

```bash
curl -X POST "https://example.com/neosidekick/api/upload-media-asset" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com/images/hero.jpg",
    "title": "Homepage Hero",
    "caption": "Hero image for homepage"
  }'
```

### Response

```json
{
  "created": true,
  "sourceUrl": "https://example.com/images/hero.jpg",
  "asset": {
    "identifier": "f32afaf9-8b14-4f55-ae67-dcb730cbf0b0",
    "filename": "hero.jpg",
    "title": "Homepage Hero",
    "caption": "Hero image for homepage",
    "mediaType": "image/jpeg",
    "previewUrl": "https://example.com/_Resources/Persistent/f/3/2/hero.jpg"
  }
}
```

### Error Responses

**400 Bad Request** - Invalid URL or malformed payload:

```json
{
  "error": "Bad Request",
  "message": "The \"url\" field must be a valid URL."
}
```

**502 Upstream Error** - Download failed:

```json
{
  "error": "Upstream Error",
  "message": "Downloading the source URL failed with HTTP status 404."
}
```

---

## 7. Apply Patches API

Apply atomic patches to the content repository. Supports creating, updating, moving, and deleting nodes with transaction-based rollback. A batch may create nested structures in one call: a `createNode` patch can declare a batch-local `ref`, and later patches address the created node as `$<ref>` (see [Batch-Local References](#batch-local-references)).

### Endpoint

```http
POST /neosidekick/api/apply-patches
```

### Request Body

```json
{
  "dimensions": {"language": ["de"]},
  "patches": [
    {
      "operation": "createNode",
      "positionRelativeToNodeId": "uuid-parent",
      "nodeType": "CodeQ.Site:Content.Text",
      "position": "into",
      "properties": {"text": "<p>Hello</p>"},
      "ref": "intro"
    },
    {
      "operation": "createNode",
      "positionRelativeToNodeId": "$intro",
      "nodeType": "CodeQ.Site:Content.Text",
      "position": "after",
      "properties": {"text": "<p>Placed right behind the first text</p>"}
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
| `dimensions` | object | No | `{}` | Content dimensions |
| `patches` | array | **Yes** | - | Array of patch operations, executed in order |
| `workspace` | string | No | - | **Ignored.** The patches are always applied to the personal workspace of the authenticated user (see [Workspace Limitations](#workspace-limitations)); the field is accepted for backwards compatibility and has no effect |
| `dryRun` | bool | No | - | **Removed in 3.1.0.** A truthy value is refused with HTTP 422 and nothing is written; `false` or absent is ignored (see [Dry-Run Mode](#dry-run-mode)) |

Every field named `positionRelativeToNodeId`, `nodeId` or `targetNodeId` accepts a node UUID **or** a batch-local reference (`$<ref>`, `$<ref>/<childName>`) to a node created by an earlier patch of the same request.

### Patch Operations

#### createNode

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `operation` | string | **Yes** | - | Must be `createNode` |
| `positionRelativeToNodeId` | string | **Yes** | - | UUID or reference of the anchor node. For position `into`: this is the parent. For `before`/`after`: this is the sibling |
| `nodeType` | string | **Yes** | - | Full NodeType name |
| `position` | string | No | `into` | `into`, `before`, or `after` |
| `properties` | object | No | `{}` | Initial property values |
| `ref` | string | No | - | Batch-local name for the created node, `^[A-Za-z][A-Za-z0-9_-]{0,63}$`, unique within the request. Later patches address the node as `$<ref>`. Only allowed on `createNode` |

#### updateNode

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `operation` | string | **Yes** | - | Must be `updateNode` |
| `nodeId` | string | **Yes** | - | UUID or reference of node to update |
| `properties` | object | **Yes** | - | Properties to set |

#### moveNode

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `operation` | string | **Yes** | - | Must be `moveNode` |
| `nodeId` | string | **Yes** | - | UUID or reference of node to move |
| `targetNodeId` | string | **Yes** | - | UUID or reference of target/anchor node |
| `position` | string | No | `into` | `into`, `before`, or `after` |

#### deleteNode

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `operation` | string | **Yes** | - | Must be `deleteNode` |
| `nodeId` | string | **Yes** | - | UUID or reference of node to delete |

### Batch-Local References

Node ids are minted by the server and only surface in the response, so without references a nested structure (container → items → texts) needs one request per depth level. A `createNode` patch may instead declare `"ref": "<name>"`; any anchor field of a **later** patch (`positionRelativeToNodeId`, `nodeId`, `targetNodeId`) may then hold, instead of a UUID:

| Anchor | Meaning |
|--------|---------|
| `$<ref>` | The node created by the patch that declared `ref` |
| `$<ref>/<childName>` | One auto-created child node (a `childNodes:` key of the created node's NodeType, e.g. `main` of a page), one segment only |

Rules:

- A `ref` must be declared by an **earlier** patch (index order); it must be unique within the request and match `^[A-Za-z][A-Za-z0-9_-]{0,63}$`. `ref` on `updateNode`, `moveNode` or `deleteNode` is refused.
- Node ids are UUIDs, so the `$` prefix cannot collide with a stored node. Requests without refs behave exactly as before.
- Refs are request-scoped aliases: they are never persisted, never echoed in success rows and never an authorization input.
- Validation is a single pre-pass over the whole request before the transaction opens. A `$<ref>` anchor is validated against the declared NodeType (`allowsChildNodeType`), a `$<ref>/<childName>` anchor against the NodeType's grandchild constraints for that child; an unknown child name is refused with the valid names. Stored UUID anchors keep the parent-type check they always had, so a type that only the auto-created `main` forbids is still refused at execution (rolled back, `rollbackPerformed: true`).

**Sibling order.** Repeated `into` on one anchor appends in patch order. Repeated `before X` keeps patch order. Repeated `after X` **reverses** the order, because each node is inserted directly behind `X`. To place several new nodes after an existing node in order, anchor the first on it and each further one on the previous patch's `$ref` with `after`:

```json
{
  "patches": [
    {"operation": "createNode", "positionRelativeToNodeId": "uuid-existing", "nodeType": "CodeQ.Site:Content.Text", "position": "after", "ref": "t1", "properties": {"text": "<p>1</p>"}},
    {"operation": "createNode", "positionRelativeToNodeId": "$t1", "nodeType": "CodeQ.Site:Content.Text", "position": "after", "ref": "t2", "properties": {"text": "<p>2</p>"}},
    {"operation": "createNode", "positionRelativeToNodeId": "$t2", "nodeType": "CodeQ.Site:Content.Text", "position": "after", "properties": {"text": "<p>3</p>"}}
  ]
}
```

A page with content in one request:

```json
{
  "patches": [
    {"operation": "createNode", "positionRelativeToNodeId": "uuid-parent-page", "nodeType": "CodeQ.Site:Document.Page", "position": "into", "ref": "page", "properties": {"title": "New page"}},
    {"operation": "createNode", "positionRelativeToNodeId": "$page/main", "nodeType": "CodeQ.Site:Content.Accordion", "position": "into", "ref": "acc"},
    {"operation": "createNode", "positionRelativeToNodeId": "$acc", "nodeType": "CodeQ.Site:Content.Accordion.Section", "position": "into", "ref": "s1", "properties": {"title": "First"}},
    {"operation": "createNode", "positionRelativeToNodeId": "$s1", "nodeType": "CodeQ.Site:Content.Text", "position": "into", "properties": {"text": "<p>Body</p>"}}
  ]
}
```

### Example Request

```bash
curl -X POST "https://example.com/neosidekick/api/apply-patches" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "dimensions": {"language": ["de"]},
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
        "positionRelativeToNodeId": "parent-uuid",
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
- Auto-created child nodes (fixed children configured in NodeType's `childNodes`), recursively

Since 3.1.0 nothing else is created; earlier versions also ran the node type's `options.template` (Flowpack.NodeTemplates) after `createNode`, which added template children and could null caller-supplied properties.

The MCP tool formats this as JSX matching the `getDocumentContent` tool output:

```text
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
  "error": {
    "message": "Property 'invalidProp' is not declared in NodeType",
    "patchIndex": 1,
    "operation": "updateNode",
    "nodeId": "uuid-123",
    "ref": null
  },
  "rollbackPerformed": true
}
```

| Field | Type | Description |
|-------|------|-------------|
| `error.message` | string | What failed **and what to do**: the allowed child types of the actual parent, the valid auto-created child names, the property rule violated, or the refs declared before the failing patch for an undefined reference. Names the alias and, once resolved, the node id |
| `error.patchIndex` | int | Index of the failing patch in `patches` |
| `error.operation` | string | Operation of the failing patch (`unknown` if the patch could not be parsed) |
| `error.nodeId` | string\|null | The node UUID the failing patch anchored on, if any. **Always a UUID or `null`, never a `$…` alias** |
| `error.ref` | string\|null | The batch-local reference the failing patch anchored on, exactly as sent (`$acc` or `$acc/main`); `null` when the patch used a UUID or no anchor is involved |
| `rollbackPerformed` | bool | `true`: the transaction was opened and rolled back. `false`: the request was refused during validation and nothing was attempted. In both cases nothing was written |

A reference failure, refused before the transaction:

```json
{
  "success": false,
  "error": {
    "message": "Undefined reference \"$acc\" in \"positionRelativeToNodeId\" at patch 3: no earlier createNode patch declares \"ref\": \"acc\". Refs declared before patch 3: \"page\", \"intro\".",
    "patchIndex": 3,
    "operation": "createNode",
    "nodeId": null,
    "ref": "$acc"
  },
  "rollbackPerformed": false
}
```

### Dry-Run Mode

`dryRun` was removed in 3.1.0: a failed batch writes nothing, so a separate validation run has no purpose. A request with a truthy `dryRun` is refused with HTTP 422 and the failure body (`error.message`: "dry-run is no longer supported; apply the batch, a failure writes nothing", `error.operation`: `batch`, `rollbackPerformed: false`), and nothing is written.

### Transaction Semantics

- All patches are executed within a single database transaction, in request order; patches are never reordered
- If any patch fails, all previous changes are rolled back and discarded; nothing of the batch is written, not even by the end-of-request persist (`rollbackPerformed: true`)
- All patches are validated before the transaction opens (node existence, batch-local references, child constraints, and properties using the `Flowpack.NodeTemplates` PropertiesProcessor); the first error refuses the whole request (`rollbackPerformed: false`)
- On installs with `Neos.Neos.eventLog.enabled: true` (off by default), a rolled-back batch may still leave `Node.Updated` rows in the event log, because the event log collects the changed nodes in memory and materialises them at the end of the request; this is accepted — no node data is written

### Workspace Limitations

**Important:** The JWT Bearer token *does* authenticate as a Neos backend user — the account encoded in its `sub`/`account_id` claims. `apply-patches` therefore writes to **that** user's personal workspace (e.g. `user-admin`); it is not a public/anonymous request. A given token can only write to the workspace of the account it was minted for. A `workspace` field in the request body is ignored.

### Error Response

**400 Bad Request** - Invalid request structure:

```json
{
  "error": "Bad Request",
  "message": "Missing required field \"patches\""
}
```

**422 Unprocessable Entity** - Patch validation or execution failed (see failure response above)

---

## 7. Backend Service API

Internal service endpoint for the Neos backend UI integration. Used by the NEOSidekick backend module.

### Endpoint

```http
GET/POST /neosidekick/aiassistant/service/{action}
```

### Available Actions

This endpoint supports various actions for the backend UI. Refer to `BackendServiceController.php` for specific action implementations.

---

## Common Patterns

### Dimension Format

Dimensions are passed as URL-encoded JSON strings:

```text
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

```text
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
              │ Authorization: Bearer {agent JWT}
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

1. **Authentication**: Access is gated by an RS256 agent JWT (see [Authentication](#authentication)), not a static API key. The token expires one hour after it was minted and is renewed through the refresh endpoint; only legacy `kid`-less HS256 tokens are bound to the backend session they were minted from.
2. **HTTPS**: Always use HTTPS in production
3. **Workspace Access**: Writes act as the backend account encoded in the JWT and land in that user's personal workspace; reads may span workspaces - consider access control
4. **Hidden Content**: Hidden nodes may be included - handle appropriately
5. **Rate Limiting**: Consider implementing rate limiting for large sites
6. **Security Framework**: Authentication is enforced by Flow's security framework via the `NEOSidekick.AiAssistant:JwtApi` provider (`JwtProvider` + `JwtToken` + `JwtEntryPoint`, configured in `Settings.Internal.yaml`), which validates the JWT signature and the backend account (and, for legacy `kid`-less tokens, the referenced session). The controllers listed in that provider's request pattern are **not** granted anonymous/public access.

---

## Related Files

### Controllers

API controllers are placed directly in the Controller namespace (not in a subpackage) due to Flow routing requirements:

- `Classes/Controller/NodeTypeSchemaApiController.php` - NodeType schema endpoint
- `Classes/Controller/NodeTreeSchemaApiController.php` - Node tree endpoint
- `Classes/Controller/DocumentNodeListApiController.php` - Document list endpoint
- `Classes/Controller/SearchNodesApiController.php` - Search nodes endpoint
- `Classes/Controller/SearchMediaAssetsApiController.php` - Search media assets endpoint
- `Classes/Controller/UploadMediaAssetApiController.php` - Upload media assets from URL endpoint
- `Classes/Controller/ApplyPatchesApiController.php` - Apply patches endpoint
- `Classes/Controller/BackendServiceController.php` - Backend UI service

### Services

Data extraction services that provide raw data to the controllers:

- `Classes/Service/NodeTypeSchemaExtractor.php` - Extracts NodeType definitions
- `Classes/Service/NodeTreeExtractor.php` - Traverses and extracts node trees
- `Classes/Service/DocumentNodeListExtractor.php` - Extracts document node lists
- `Classes/Service/SearchNodesExtractor.php` - Searches nodes by property values
- `Classes/Service/MediaAssetSearchService.php` - Searches media assets by title, filename, caption
- `Classes/Service/MediaAssetUploadService.php` - Uploads media assets into library from remote URLs
- `Classes/Service/NodePatchService.php` - Applies atomic patches with transaction support
- `Classes/Service/PatchValidator.php` - Static pre-pass over a batch: node existence, batch-local references (`ref`, `$<ref>`, `$<ref>/<childName>`), child constraints, properties via the NodeTemplates PropertiesProcessor
- `Classes/Service/PatchValidation/NodeDescriptor.php` - What the validator knows about a stored or pending anchor (type, parent, constraint semantics)

### Configuration

- `Configuration/Routes.yaml` - API route definitions
- `Configuration/Policy.yaml` - Privilege targets for the API controllers (`NEOSidekick.AiAssistant:CanUse`)
- `Configuration/Settings.Internal.yaml` - JWT authentication provider (`NEOSidekick.AiAssistant:JwtApi`) and request-pattern configuration
- `Classes/Security/Authentication/**` - JWT token, provider and entry point
- `Classes/Service/AgentTokenService.php` - Mints and verifies the agent JWT

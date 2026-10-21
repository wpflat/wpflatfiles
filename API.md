# WPFlat Files REST API (for the explorer UI)

All routes live under the WPFlat Core REST base (`wpflatcore/v1`), reached through the neutral address `Urls::api()` (e.g. `https://site/bd/api`).
Auth is the WordPress cookie plus the `X-WP-Nonce` header (nonce = `wp_create_nonce('wp_rest')`). JSON in, JSON out.
Staff previewing the portal as a client send `X-WPFlat-As: <client id>` on every request (the server then answers as that client).
Errors are `{ code, message, data: { status } }` with 4xx status.

## Boot (portal / front end)
The portal page prints a mount point and `window.WPFlatFilesBoot`:
```
{ mode: 'portal'|'app', api: 'https://site/bd/api', nonce: '…', as: <client id|undefined>, brand: 'Business name', config: <GET /fm/config> }
```
and loads `wpflatfiles-explorer.js` / `wpflatfiles-explorer.css` (one standalone script that bundles its own Vue; it mounts into `#wpflatfiles-root`).
The staff app (WPFlat Core's Vue shell) mounts the same explorer by calling `window.WPFlatFilesExplorer.mount( el, boot )` after loading the same script.

## Concepts
- Folders form a tree. A person only sees folders they have a level on; hidden folders do not appear anywhere (tree, list, search, breadcrumbs).
- `level` per folder: 0 none, 1 view, 2 upload, 3 edit, 4 manage.
  - view: see and download. upload: also add files. edit: also create folders, rename, move, delete, share links. manage: also change who has access.
- `inherit`: a folder either uses its parent's access (`true`) or has its own rules (`false`). Top-level folders always have their own.
- Rule: `{ type: 'team'|'users'|'group'|'client', id, level: 'view'|'upload'|'edit'|'manage' }` (`team` and `users` have id 0).
  Words: "group" and "client" are renamable by the owner (Class, Cohort, Student, Learner…): the explorer must take the labels from `WPFlatCore.t`/`tp`
  in the app; in the portal use the labels in `config.words` ({ client: {s,p}, group: {s,p} }) — **the server sends these in config.words**.
- Deleting moves to the recycle bin (restorable); the bin purges itself after `trash_days`.

## Endpoints
| Method + path | Purpose |
|---|---|
| `GET /fm/config` | `{ themes:{id:label}, theme, default_theme, theme_switch, view, max_mb, chunk_size, users_can_upload, staff, manager, simulated, user, allowed:[ext], words }` |
| `POST /fm/prefs` `{theme?, view?}` | remember this person's theme and view (details/list/tiles/icons); returns config |
| `GET /fm/tree` | `{ folders:[ {id,parent_id,name,color,level,inherit,items,modified} ], roots:[ids] }` — every folder the person can open; `roots` = entry points (top of what they can see) |
| `GET /fm/list?folder=ID&q=&sort=name\|date\|size\|type&dir=asc\|desc` | folder contents. `folder=0` = the top (entry points). `q` = search everything visible. → `{ folder:{…,path:[{id,name}]}\|null, can:{upload,edit,manage,create_folder}, folders:[…folder rows (+path in search)], files:[…file rows], query }` |
| `POST /fm/folders` `{parent_id,name,color?,description?}` | new folder (parent 0 = top level, managers only) |
| `PATCH /fm/folders/{id}` `{name?,color?,description?,parent_id?}` | rename / recolour / move |
| `DELETE /fm/folders/{id}` | to the recycle bin |
| `POST /fm/folders/{id}/copy` `{parent_id}` | copy a folder with everything in it (`parent_id` 0 = top level, managers only) → the new folder row, named "<name> - Copy". The explorer calls it when a folder is pasted (Ctrl+C, Ctrl+V) or Ctrl-dragged onto a folder |
| `GET /fm/folders/{id}/access` | manage only → `{ inherit, top_level, rules:[{type,id,level,name}], from:{id,name}\|null, levels:[…] }` |
| `PUT /fm/folders/{id}/access` `{inherit, rules:[{type,id,level}]}` | replace the folder's access (rules ignored when inherit=true) |
| `GET /fm/pickers?type=group\|client&q=` | staff only: `[ {id,name} ]` for the access dialog |
| `POST /fm/upload/init` `{folder_id, name, size, mime?, file_id?, note?}` | start a chunked upload (`file_id` = new version of that file) → `{ upload_id, chunk_size }` |
| `POST /fm/upload/chunk` multipart `upload_id`, `index` (0,1,2… in order), `chunk` (file part) | send one piece → `{received,next}`; resend the same index is harmless; on a 409 `data.next` says which index is expected |
| `POST /fm/upload/finish` `{upload_id}` | assemble → the file row |
| `DELETE /fm/upload/{upload_id}` | cancel |
| `POST /fm/links` `{folder_id, name, url, description?}` | add a link (a video or file hosted elsewhere) → the file row (`is_link`). Needs upload level |
| `PATCH /fm/items/{id}` `{name?,folder_id?,description?,tags?,link_url?}` | rename / move a file; `link_url` changes a link's address |
| `DELETE /fm/items/{id}` | file to the recycle bin |
| `POST /fm/items/{id}/copy` `{folder_id}` | copy into a folder (a link item copies the link) |
| `GET /fm/items/{id}/versions` | `[ {version,name,size,note,created_at,uploader,current,download_url} ]` |
| `POST /fm/items/{id}/versions/{n}/restore` | make version n current (as a new version) |
| `GET /fm/items/{id}/shares` / `POST /fm/items/{id}/share` `{expires_days?(7, 0=never), password?, max_downloads?}` / `DELETE /fm/shares/{id}` | public links → `{id,url,expires_at,protected,downloads,max_downloads,active}` |
| `GET /fm/trash` | `{ items:[{type:'file'\|'folder',id,name,deleted_at,parent_id,size}], days }` |
| `POST /fm/trash/restore` `{type,id}`; `POST /fm/trash/purge` `{type,id}` or `{all:true}` (managers) | |
| `GET /fm/settings` / `POST /fm/settings` (admins) | `{theme, theme_switch, users_can_upload, max_mb, chunk_mb, trash_days, default_view}` |

### File row
`{ id, folder_id, name, ext, mime, kind: image|video|audio|pdf|doc|sheet|slides|text|archive|other, size, version, description, tags, modified, created, uploader, inline (browser can show it), view_url (inline stream, supports Range), download_url, thumb_url ('' unless image) , path (only in search results) }`
Use `view_url` as the `src` of `<img>`, `<video>`, `<audio>`, `<iframe>`(PDF). Text previews: fetch `view_url` (text/plain, csv, markdown) and show as text.

Links: `is_link` (bool), `link_url`, `embed_url` ('' unless the host can be embedded: YouTube, Vimeo, Loom, Google Drive preview, Bunny Stream), `kind: 'link'`, `ext: 'link'`; `view_url`, `download_url` and `thumb_url` are '' and `inline` is false.
The explorer shows a link with a chain icon (tinted like a video when `embed_url` is a video host). Opening one (double-click, Enter, or the details pane) shows `embed_url` in a sandboxed 16:9 iframe
(`sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"`, `allow="fullscreen; picture-in-picture"`, `referrerpolicy="strict-origin-when-cross-origin"`); with no `embed_url` it shows an "Open link" button (`target=_blank rel="noopener noreferrer"`) and the address.
Download, Share, Versions and New version are hidden for links; Properties shows the address and lets editors change it (`PATCH link_url`); the type reads "Link". The New menu has "Link…" when the person can upload.

CSV / TSV files (`ext` csv or tsv): the preview pane and the viewer fetch the first ~96 KB of `view_url` and draw a table (client-side RFC 4180 parser; comma, semicolon or tab detected; first row is the sticky header; at most 200 rows and 30 columns, with a note "Showing the first N rows"). Text and markdown stay plain text.

### Folder row
`{ id, parent_id, name, color, description, modified, level, inherit, items, zip_url, path (search only) }`
`zip_url` is a normal authenticated GET that answers with the folder (everything the person can see inside) as a zip attachment. The explorer offers "Download as zip" when one folder is selected (context menu, command bar Download, details pane) by pointing a hidden link at it.

## Not served by the API
- `/?bd_file=<id>[&dl=1][&v=<n>][&thumb=1]` — the file bytes (cookie auth). `/share/<token>` — public share page.

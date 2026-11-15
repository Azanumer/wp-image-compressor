# Image Compressor

Compress JPEG, PNG and WebP uploads automatically — re-encodes at a quality you choose, strips EXIF data, and only keeps the result when it is actually smaller. Includes a bulk compressor for existing media.

## Features

- **Compress on upload** — every new JPEG, PNG or WebP is re-encoded via WordPress's image editor (GD or Imagick) at your chosen quality. Runs before thumbnails are generated, so thumbnails are built from the compressed original.
- **Never makes files bigger** — the re-encoded file is written to a temp file first and only replaces the original when it is actually smaller.
- **EXIF stripped** — re-encoding drops camera metadata and GPS data from uploads, which is good for privacy and size.
- **Per-format quality** — separate quality settings for JPEG, PNG and WebP (default 82), plus a "skip files smaller than X KB" threshold.
- **Bulk compressor** — **Tools → Compress Images** walks the whole Media Library in batches of 20 with auto-continue, marks each attachment as processed, and reports total bytes saved.
- **Filter** — `wic_quality` overrides the quality per MIME type in code.

## Installation

1. Upload the `wp-image-compressor` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins** in WordPress admin.
3. Tune the quality under **Settings → Image Compressor**; bulk-compress existing media under **Tools → Compress Images**.

## Notes

- Animated GIFs are skipped on purpose — re-encoding would break the animation.
- Files that fail to compress (unsupported editor, unreadable file) are marked during bulk runs so they are not retried forever.

## Uninstall

Deleting the plugin removes its settings, the saved-bytes total, and the `_wic_compressed` / `_wic_saved_bytes` attachment meta. Compressed files stay compressed (that is the point).

## License

MIT — see [LICENSE](LICENSE).

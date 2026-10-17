#!/usr/bin/env python3
"""
Builds the optimised paper texture derivatives used by the design system (assets/styles/abstracts/_texture.scss).

Sources (tracked copies of the originals, assets/images/textures/source/; public/images/ is git-ignored and untouched):
  - watercolor_paper_5_by_craftmans_d2772t4-fullview.jpg   almost-white warm paper with coloured fibres
  - hard-paper-texture.jpg                                  mid-tone kraft cardboard

Outputs (assets/images/textures/, tracked; seamless square WebP tiles, same grain, different tonal "levels" so that a CSS
blend mode finds what it needs on any host colour):

  paper-multiply.webp   highlight-biased (mean ~0.93)  -> mix-blend-mode: multiply on light hosts
  paper-neutral.webp    mid-grey grain (mean 0.5)      -> soft-light / overlay on mid-tone hosts
  paper-screen.webp     shadow-biased  (mean ~0.10)    -> screen on dark hosts
  kraft-multiply.webp / kraft-neutral.webp / kraft-screen.webp   same recipe for the hard paper (desaturated)

Run on the host (needs python3, numpy, Pillow):  python3 design-system/scripts/make-textures.py
"""
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter

ROOT = Path(__file__).resolve().parents[2]
TEXTURES = ROOT / "assets" / "images" / "textures"
SOURCES = TEXTURES / "source"
PAPER_SRC = SOURCES / "watercolor_paper_5_by_craftmans_d2772t4-fullview.jpg"
KRAFT_SRC = SOURCES / "hard-paper-texture.jpg"
TILE = 640
QUALITY = 78


def load(path: Path) -> np.ndarray:
    return np.asarray(Image.open(path).convert("RGB"), dtype=np.float32) / 255.0


def crop_square(img: np.ndarray, size: int, left: int, top: int) -> np.ndarray:
    return img[top : top + size, left : left + size]


def make_seamless(tile: np.ndarray) -> np.ndarray:
    """Variance-preserving blend of the tile with its half-shifted copy: the borders become the (continuous)
    centre lines of the original, so the tile repeats without a visible seam."""
    n = tile.shape[0]
    shifted = np.roll(tile, (n // 2, n // 2), axis=(0, 1))
    w1 = np.sin(np.linspace(0, np.pi, n, dtype=np.float32)) ** 2
    w = (w1[:, None] * w1[None, :])[..., None]
    mean = tile.mean(axis=(0, 1), keepdims=True)
    mixed = tile * w + shifted * (1 - w)
    norm = np.sqrt(w**2 + (1 - w) ** 2)
    return np.clip((mixed - mean) / norm + mean, 0, 1)


def blur(arr: np.ndarray, radius: float) -> np.ndarray:
    out = np.empty_like(arr)
    for c in range(arr.shape[2]):
        im = Image.fromarray((arr[..., c] * 255).astype(np.uint8))
        # wrap-around blur keeps the tile seamless: pad by mirroring a third of the size on every side
        n = arr.shape[0]
        big = Image.new("L", (n * 3, n * 3))
        for dx in (-1, 0, 1):
            for dy in (-1, 0, 1):
                big.paste(im, (n + dx * n, n + dy * n))
        big = big.filter(ImageFilter.GaussianBlur(radius))
        out[..., c] = np.asarray(big.crop((n, n, 2 * n, 2 * n)), dtype=np.float32) / 255.0
    return out


def luma(arr: np.ndarray) -> np.ndarray:
    return (arr * np.array([0.2126, 0.7152, 0.0722], dtype=np.float32)).sum(axis=2)


def desaturate(arr: np.ndarray, amount: float) -> np.ndarray:
    g = luma(arr)[..., None]
    return g + (arr - g) * (1 - amount)


def save(arr: np.ndarray, name: str) -> None:
    img = Image.fromarray((np.clip(arr, 0, 1) * 255 + 0.5).astype(np.uint8))
    path = TEXTURES / name
    img.save(path, "WEBP", quality=QUALITY, method=6)
    print(f"{name}: {path.stat().st_size / 1024:.0f} KB, mean {np.asarray(img).mean() / 255:.3f}")


STATS: dict = {}


def record(name: str, arr: np.ndarray, low: bool) -> None:
    """Percentiles used by `npm run contrast` to check text against the worst grain pixel."""
    g = luma(arr)
    STATS[name] = {"mean": round(float(g.mean()), 4), "p05": round(float(np.percentile(g, 5)), 4), "p95": round(float(np.percentile(g, 95)), 4)}


def derive(base: np.ndarray, prefix: str, *, grain_gain: float, screen_gain: float, saturation: float, hp_radius: float, screen_saturation: float | None = None) -> None:
    """`base` is a seamless RGB tile (0..1). Produces the three tonal variants."""
    # High-pass: drop the low-frequency mottling, keep fibres/grain, re-centre on zero.
    low = blur(base, hp_radius)
    delta = base - low  # signed, ~zero mean, per channel
    grey = luma(delta)[..., None]
    g = grey + (delta - grey) * saturation  # keep `saturation` of the fibre colours

    # multiply-ready: white minus the amplified darkness of the grain (never darker than 45 %)
    multiply = 1 - np.clip(np.clip(-g, 0, None) * grain_gain, 0, 0.55)
    # a touch of the original low-frequency cloud keeps it "paper" rather than "noise"
    cloud = luma(blur(base, hp_radius * 0.5))[..., None]  # colourless, mean-normalised
    multiply = np.clip(multiply * (cloud / cloud.mean()) ** 2.5, 0, 1)

    # neutral: mid-grey centred grain for soft-light / overlay
    neutral = np.clip(0.5 + g * grain_gain * 0.9, 0, 1)

    # screen-ready: black plus the amplified brightness of the grain (light fibres win, dark flecks add a little)
    # (inverted fibre colours look sickly on dark paper: keep only part of their saturation)
    sg = grey + (delta - grey) * (saturation if screen_saturation is None else screen_saturation)
    screen = np.clip((np.clip(sg, 0, None) + np.clip(-sg, 0, None) * 0.5) * screen_gain, 0, 0.75)

    save(multiply, f"{prefix}-multiply.webp")
    save(neutral, f"{prefix}-neutral.webp")
    save(screen, f"{prefix}-screen.webp")
    record(f"{prefix}Multiply", multiply, True)
    record(f"{prefix}Screen", screen, False)


def main() -> None:
    paper = load(PAPER_SRC)  # 700 high
    tile = make_seamless(crop_square(paper, 700, 150, 0))
    tile = np.asarray(Image.fromarray((tile * 255).astype(np.uint8)).resize((TILE, TILE), Image.LANCZOS), dtype=np.float32) / 255
    derive(tile, "paper", grain_gain=3.2, screen_gain=7.0, saturation=1.0, hp_radius=90, screen_saturation=0.45)

    kraft = load(KRAFT_SRC)  # 1920x1280: take a native-resolution window so the fibres keep their size
    tile = make_seamless(crop_square(kraft, TILE, 640, 320))
    derive(tile, "kraft", grain_gain=2.8, screen_gain=4.5, saturation=0.1, hp_radius=40)


if __name__ == "__main__":
    main()
    # Effective opacities: ratio(1) × strength (canvas .4 light / .55 dark, card .5) × recipe gain (paper tone .55, deep .5),
    # keep in sync with assets/styles/base/_textures.scss, components/_card.scss and abstracts/_texture.scss.
    STATS["canvasAlpha"] = {"light": 0.4 * 0.55, "dark": 0.55 * 0.5}
    STATS["cardAlpha"] = {"light": 0.5 * 0.55, "dark": 0.5 * 0.5}
    import json

    (TEXTURES / "stats.json").write_text(json.dumps(STATS, indent=2) + "\n")
    print("stats.json written")

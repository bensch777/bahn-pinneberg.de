"""
Erzeugt Favicon, Logo im Kopf und die Webapp-Icons aus dev/icon/zug-front.webp
(Nordbahn-Triebwagen von vorn, transparenter Hintergrund).

    python3 dev/icon/build.py      # braucht Pillow

favicon.ico (16/32/48) und icon-64.png (Favicon, Logo im Kopf, Grundlage fuer das
Favicon mit Statuspunkt in dashboard.js) bleiben transparent, ebenso icon-192.png
und icon-512.png fuer den Browser. icon-180.png (iPhone-Homescreen) bekommt einen
weissen Grund, weil iOS Transparenz schwarz fuellt; icon-512-maskable.png haelt den
Zug in der sicheren Zone (80 % Kreis), Android schneidet den Rand je nach Form ab.
"""

from pathlib import Path

from PIL import Image, ImageFilter

ROOT = Path(__file__).resolve().parents[2]
QUELLE = Path(__file__).with_name('zug-front.webp')
WEISS = (255, 255, 255, 255)


def quadrat(bild):
    """Auf den Inhalt zuschneiden und mittig auf ein transparentes Quadrat setzen."""
    bild = bild.crop(bild.getbbox())
    seite = max(bild.size)
    q = Image.new('RGBA', (seite, seite), (0, 0, 0, 0))
    q.paste(bild, ((seite - bild.width) // 2, (seite - bild.height) // 2))
    return q


def icon(zug, groesse, anteil=1.0, grund=None):
    """Zug auf anteil der Kantenlaenge verkleinern, mittig auf groesse x groesse."""
    innen = max(1, round(groesse * anteil))
    klein = zug.resize((innen, innen), Image.LANCZOS)
    if innen <= 64:
        # kleine Groessen verwaschen sonst
        klein = klein.filter(ImageFilter.UnsharpMask(radius=0.6, percent=60, threshold=1))
    bild = Image.new('RGBA', (groesse, groesse), grund or (0, 0, 0, 0))
    off = (groesse - innen) // 2
    bild.alpha_composite(klein, (off, off))
    return bild


def main():
    zug = quadrat(Image.open(QUELLE).convert('RGBA'))
    icon(zug, 64).save(ROOT / 'icon-64.png', optimize=True)
    icon(zug, 192, 0.96).save(ROOT / 'icon-192.png', optimize=True)
    icon(zug, 512, 0.96).save(ROOT / 'icon-512.png', optimize=True)
    icon(zug, 180, 0.8, WEISS).convert('RGB').save(ROOT / 'icon-180.png', optimize=True)
    icon(zug, 512, 0.64, WEISS).convert('RGB').save(ROOT / 'icon-512-maskable.png', optimize=True)
    ico = [icon(zug, s) for s in (48, 32, 16)]
    ico[0].save(ROOT / 'favicon.ico', sizes=[(48, 48), (32, 32), (16, 16)], append_images=ico[1:])
    for name in ('favicon.ico', 'icon-64.png', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'icon-512-maskable.png'):
        print(name, (ROOT / name).stat().st_size, 'Bytes')


if __name__ == '__main__':
    main()

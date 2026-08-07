"""Genera le varianti del logo: favicon del pannello, icone e banner dell'app.

Tutto deriva dall'unico file sorgente docs/assets/logo.png, così un domani
basta sostituire quello e rilanciare questo script.
"""
import io, os, sys
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
from PIL import Image, ImageDraw

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(ROOT)

SRC = Image.open("docs/assets/logo.png").convert("RGB")

# Misure rilevate sul file sorgente
FPS_BOX      = (225, 467, 1056, 779)    # i tre glifi, senza le parentesi
TAGLINE_BOX  = (414, 838, 1014, 870)    # "FRAME PARING STORE"
CONTENT_BOX  = (120, 354, 1145, 876)    # tutto il blocco, parentesi comprese
GIALLO       = (252, 203, 15)
FONDO        = (5, 5, 5)


def ritaglia_con_maschera(box):
    """Ritaglia una porzione e ne ricava una maschera dalla luminosità:
    il fondo quasi nero sparisce, i glifi restano con i bordi morbidi."""
    crop = SRC.crop(box)
    grigi = crop.convert("L")
    # Il fondo sta sotto 30, i glifi sopra 180: si allunga l'intervallo
    maschera = grigi.point(lambda v: 0 if v < 24 else min(255, int((v - 24) * 255 / 140)))
    out = Image.new("RGBA", crop.size, (0, 0, 0, 0))
    out.paste(crop, (0, 0), maschera)
    return out


def marchio(lato: int) -> Image.Image:
    """Marchio compatto: FPS fra le due parentesi, senza la dicitura estesa.
    È la sola versione che resta leggibile a 16-32 px."""
    im = Image.new("RGBA", (lato, lato), FONDO + (255,))
    d = ImageDraw.Draw(im)

    # Parentesi più marcate dell'originale: a 16 px un tratto sottile sparisce
    m = round(lato * 0.075)          # distanza dal bordo
    a = round(lato * 0.30)           # lunghezza del braccio
    t = max(2, round(lato * 0.062))  # spessore

    # In alto a destra
    d.rectangle([lato - m - a, m, lato - m - 1, m + t - 1], fill=GIALLO)
    d.rectangle([lato - m - t, m, lato - m - 1, m + a - 1], fill=GIALLO)
    # In basso a sinistra
    d.rectangle([m, lato - m - t, m + a - 1, lato - m - 1], fill=GIALLO)
    d.rectangle([m, lato - m - a, m + t - 1, lato - m - 1], fill=GIALLO)

    glifi = ritaglia_con_maschera(FPS_BOX)
    larghezza = round(lato * 0.70)
    altezza = round(larghezza * glifi.height / glifi.width)
    glifi = glifi.resize((larghezza, altezza), Image.LANCZOS)
    im.alpha_composite(glifi, ((lato - larghezza) // 2, (lato - altezza) // 2))
    return im


def banner(l: int = 320, h: int = 180) -> Image.Image:
    """Banner del launcher Android TV: il blocco completo, dicitura inclusa,
    centrato su fondo scuro."""
    im = Image.new("RGB", (l, h), FONDO)
    blocco = ritaglia_con_maschera(CONTENT_BOX)
    larghezza = round(l * 0.82)
    altezza = round(larghezza * blocco.height / blocco.width)
    if altezza > h * 0.78:
        altezza = round(h * 0.78)
        larghezza = round(altezza * blocco.width / blocco.height)
    blocco = blocco.resize((larghezza, altezza), Image.LANCZOS)
    im.paste(blocco, ((l - larghezza) // 2, (h - altezza) // 2), blocco)
    return im


def salva(im: Image.Image, percorso: str):
    os.makedirs(os.path.dirname(percorso), exist_ok=True)
    im.save(percorso)
    print(f"   {percorso}  {im.size[0]}x{im.size[1]}")


print("Favicon del pannello:")
FAV = "server/cms/assets/img"
os.makedirs(FAV, exist_ok=True)
# .ico con più risoluzioni, ognuna disegnata alla sua dimensione nativa:
# rimpicciolire una resa grande sfoca le parentesi a 16 px
marchio(48).convert("RGB").save(
    f"{FAV}/favicon.ico", format="ICO",
    sizes=[(16, 16), (32, 32), (48, 48)],
    append_images=[marchio(16).convert("RGB"), marchio(32).convert("RGB")],
)
print(f"   {FAV}/favicon.ico  16/32/48")
salva(marchio(32),  f"{FAV}/favicon-32.png")
salva(marchio(180), f"{FAV}/apple-touch-icon.png")
salva(marchio(192), f"{FAV}/icon-192.png")

print("Marchio compatto per la documentazione:")
salva(marchio(512), "docs/assets/logo-mark.png")

print("Icone dell'app:")
RES = "app-androidtv/app/src/main/res"
for cartella, lato in [("mipmap-mdpi", 48), ("mipmap-hdpi", 72), ("mipmap-xhdpi", 96),
                       ("mipmap-xxhdpi", 144), ("mipmap-xxxhdpi", 192)]:
    salva(SRC.resize((lato, lato), Image.LANCZOS), f"{RES}/{cartella}/ic_launcher.png")

print("Banner del launcher TV:")
for cartella, scala in [("drawable-mdpi", 1), ("drawable-hdpi", 1.5), ("drawable-xhdpi", 2)]:
    salva(banner(round(320 * scala), round(180 * scala)), f"{RES}/{cartella}/banner.png")

"""Small synthetic fixtures, no personal data. Requires reportlab, Pillow, pypdf.
Run with a Unicode TrueType font path as the sole argument.
"""
from pathlib import Path
import sys
from io import BytesIO
from reportlab.pdfgen import canvas
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.utils import ImageReader
from PIL import Image, ImageDraw, ImageFont
from pypdf import PdfReader, PdfWriter

out = Path(__file__).resolve().parent
font = sys.argv[1]
pdfmetrics.registerFont(TTFont('FixtureFont', font))
scan = Image.new('RGB', (1600, 500), 'white')
draw = ImageDraw.Draw(scan)
draw.text((80, 130), 'LEARNFORGE SCANNED PAGE TWO', font=ImageFont.truetype(font, 60), fill='black')
draw.text((80, 230), 'Local document extraction fixture.', font=ImageFont.truetype(font, 48), fill='black')
buffer = BytesIO()
scan.save(buffer, format='PNG')

c = canvas.Canvas(str(out / 'mixed.pdf'), pagesize=(612, 792), invariant=1)
c.setFont('FixtureFont', 18)
c.drawString(60, 700, 'LearnForge: Nội dung tiếng Việt.')
c.drawString(60, 660, 'Page one has an embedded text layer.')
c.showPage()
c.drawImage(ImageReader(buffer), 36, 520, width=540, height=169)
c.showPage()
c.save()

c = canvas.Canvas(str(out / 'scan.pdf'), pagesize=(612, 792), invariant=1)
c.drawImage(ImageReader(buffer), 36, 520, width=540, height=169)
c.showPage()
c.save()

c = canvas.Canvas(str(out / 'blank.pdf'), pagesize=(612, 792), invariant=1)
c.showPage()
c.save()

writer = PdfWriter()
writer.append(PdfReader(out / 'mixed.pdf'))
writer.encrypt('fixture-only-not-a-secret')
with (out / 'encrypted.pdf').open('wb') as dest:
    writer.write(dest)
(out / 'broken.pdf').write_bytes(b'%PDF-1.7\nThis synthetic fixture has no PDF objects.\n')

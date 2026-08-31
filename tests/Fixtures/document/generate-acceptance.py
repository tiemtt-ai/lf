"""Synthetic layout fixtures. Requires reportlab/pypdf, no customer data."""
from pathlib import Path
from reportlab.pdfgen import canvas
from reportlab.platypus import Table, TableStyle
from reportlab.lib import colors
from pypdf import PdfReader, PdfWriter

out = Path(__file__).resolve().parent
c = canvas.Canvas(str(out / 'structured.pdf'), pagesize=(612, 792), invariant=1)
c.setFont('Helvetica-Bold', 20)
c.drawString(50, 740, 'LearnForge structured acceptance')
table = Table([['Quarterly sales', '', ''], ['Product', 'Q1', 'Q2'], ['ALPHA', '10', '20'], ['BETA', '30', '40']], colWidths=[180, 120, 120], rowHeights=36)
table.setStyle(TableStyle([('SPAN', (0, 0), (2, 0)), ('GRID', (0, 0), (-1, -1), 1, colors.black), ('BACKGROUND', (0, 0), (-1, 1), colors.lightgrey), ('FONTNAME', (0, 0), (-1, -1), 'Helvetica'), ('FONTSIZE', (0, 0), (-1, -1), 14), ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'), ('ALIGN', (0, 0), (-1, -1), 'CENTER')]))
table.wrapOn(c, 500, 600)
table.drawOn(c, 60, 510)
c.setFont('Helvetica', 14)
c.drawString(60, 470, 'Table 1. Synthetic sales with a merged heading.')
c.showPage()
c.setFont('Helvetica-Bold', 20)
c.drawString(50, 740, 'Chart and process diagram')
c.setFont('Helvetica', 14)
c.drawString(60, 685, 'Sales by quarter')
c.line(90, 440, 420, 440)
c.line(90, 440, 90, 650)
for x, height, label in [(140, 90, 'Q1 10'), (290, 180, 'Q2 20')]:
    c.setFillColor(colors.steelblue)
    c.rect(x, 440, 70, height, fill=1)
    c.setFillColor(colors.black)
    c.drawString(x, 415, label)
c.drawString(60, 350, 'Observed labels only; no inferred meaning.')
for x, label in [(80, 'INPUT'), (330, 'OUTPUT')]:
    c.rect(x, 200, 150, 65)
    c.drawCentredString(x + 75, 227, label)
c.line(230, 232, 330, 232)
c.line(330, 232, 316, 240)
c.line(330, 232, 316, 224)
c.showPage()
c.save()
writer = PdfWriter()
source = PdfReader(out / 'mixed.pdf')
writer.add_page(source.pages[0])
writer.add_blank_page(width=612, height=792)
writer.add_page(source.pages[1])
with (out / 'mixed-blank.pdf').open('wb') as f:
    writer.write(f)

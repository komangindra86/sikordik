"""Build user-facing PDFs from reviewed Markdown, without reading application data.

Run with the Codex bundled Python runtime (reportlab and pypdfium2).
"""
from pathlib import Path
from xml.sax.saxutils import escape
import re

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.pagesizes import A4
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak, Flowable

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'docs' / 'panduan'
OUT.mkdir(parents=True, exist_ok=True)
styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name='BodyID', fontName='Helvetica', fontSize=10.2, leading=14.2, spaceAfter=7))
styles.add(ParagraphStyle(name='TitleID', fontName='Helvetica-Bold', fontSize=22, leading=27, spaceAfter=17, textColor=colors.black))
styles.add(ParagraphStyle(name='SubID', fontName='Helvetica-Bold', fontSize=12, leading=16, spaceBefore=10, spaceAfter=7, textColor=colors.black))
styles.add(ParagraphStyle(name='CellID', fontName='Helvetica', fontSize=9, leading=12, spaceAfter=0))
styles.add(ParagraphStyle(name='HeaderID', fontName='Helvetica-Bold', fontSize=9, leading=12, textColor=colors.white))
styles.add(ParagraphStyle(name='FlowID', fontName='Helvetica', fontSize=9.8, leading=13, alignment=TA_CENTER))

def para(text, style='BodyID'):
    text = escape(text).replace('→', ' &rarr; ')
    return Paragraph(text, styles[style])

class ProcessFlow(Flowable):
    def __init__(self):
        super().__init__()
        self.width = 475
        self.height = 427

    def draw(self):
        rows = [
            ('1  Admin Kordik', 'Catat surat dan peserta, buat penempatan, ajukan ke KSM'),
            ('2  Ketua KSM kemudian Tim Kordik', 'Konfirmasi kesediaan KSM, lalu putuskan penerimaan'),
            ('3  Admin Kordik', 'Unggah dan verifikasi dokumen, aktifkan akun peserta'),
            ('4  Admin atau Sekretariat KSM kemudian Ketua KSM', 'Ajukan penugasan pendidik, lalu putuskan penugasan'),
            ('5  Peserta kemudian Pembimbing kemudian Peserta', 'Susun dan ajukan jadwal, setujui, lalu terbitkan'),
            ('6  Admin memulai stase dan semua pelaksana bekerja', 'Presensi, logbook, penilaian dan kedua survei'),
            ('7  Petugas dan Ketua KSM kemudian Admin Kordik', 'Rekap disahkan, checklist diperiksa, penyelesaian diajukan'),
            ('8  Tim Kordik', 'Setujui penyelesaian, status selesai dan data dikunci'),
        ]
        for i, (actor, action) in enumerate(rows):
            y = self.height - 47 - i * 54
            self.canv.setStrokeColor(colors.HexColor('#9AAABB'))
            self.canv.setFillColor(colors.HexColor('#F3F6F9'))
            self.canv.roundRect(0, y, self.width, 44, 5, fill=1, stroke=1)
            p = Paragraph('<b>'+escape(actor)+'</b><br/>'+escape(action), styles['FlowID'])
            _, h = p.wrap(self.width - 16, 42)
            p.drawOn(self.canv, 8, y + (44-h)/2)
            if i < len(rows)-1:
                self.canv.setStrokeColor(colors.HexColor('#40566C'))
                self.canv.line(self.width/2, y-1, self.width/2, y-8)
                self.canv.line(self.width/2, y-8, self.width/2-3, y-5)
                self.canv.line(self.width/2, y-8, self.width/2+3, y-5)

def table(lines):
    data = [[c.strip() for c in line.strip().strip('|').split('|')] for line in lines]
    data = [r for r in data if not all(re.fullmatch(r'[-: ]+', c) for c in r)]
    widths = [127, 185, 163] if len(data[0]) == 3 else [475 / len(data[0])] * len(data[0])
    cells = [[para(c, 'HeaderID' if i == 0 else 'CellID') for c in row] for i, row in enumerate(data)]
    t = Table(cells, colWidths=widths, repeatRows=1, hAlign='LEFT')
    rules = [('BACKGROUND', (0,0), (-1,0), colors.HexColor('#263D53')),
             ('GRID', (0,0), (-1,-1), .4, colors.HexColor('#D9D9D9')),
             ('VALIGN', (0,0), (-1,-1), 'MIDDLE'),
             ('LEFTPADDING', (0,0), (-1,-1), 7), ('RIGHTPADDING', (0,0), (-1,-1), 7),
             ('TOPPADDING', (0,0), (-1,-1), 7), ('BOTTOMPADDING', (0,0), (-1,-1), 7)]
    for i in range(1,len(cells)):
        if i % 2 == 0:
            rules.append(('BACKGROUND', (0,i), (-1,i), colors.HexColor('#F1F5F8')))
    t.setStyle(TableStyle(rules))
    return t

def parse_page(text):
    flow = []
    lines = text.strip().splitlines()
    i = 0
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue
        if line.startswith('|'):
            rows = []
            while i < len(lines) and lines[i].strip().startswith('|'):
                rows.append(lines[i]); i += 1
            flow.extend([table(rows), Spacer(1,10)])
            continue
        if line == '```mermaid':
            flow.extend([ProcessFlow(), Spacer(1,10)])
            i += 1
            while i < len(lines) and lines[i].strip() != '```':
                i += 1
        elif line.startswith('# '):
            flow.append(para(line[2:], 'TitleID'))
        elif line.startswith('## '):
            flow.append(para(line[3:], 'SubID'))
        elif line.startswith('- '):
            flow.append(para('• ' + line[2:]))
        else:
            flow.append(para(line))
        i += 1
    return flow

def footer(canvas, doc):
    canvas.saveState()
    canvas.setFont('Helvetica', 8)
    canvas.setFillColor(colors.HexColor('#52606D'))
    canvas.drawString(60, 31, 'SIKORDIK RSBM | Panduan pengguna | Edisi 1 - September 2026')
    canvas.drawRightString(A4[0]-60, 31, str(doc.page))
    canvas.restoreState()

class GuideDocument(SimpleDocTemplate):
    def afterFlowable(self, flowable):
        if isinstance(flowable, Paragraph) and flowable.style.name == 'TitleID':
            key = f'page-{self.page}'
            self.canv.bookmarkPage(key)
            self.canv.addOutlineEntry(flowable.getPlainText(), key, level=0)

def build(filename, pages, title):
    doc = GuideDocument(str(OUT / filename), pagesize=A4, rightMargin=60, leftMargin=60,
                            topMargin=44, bottomMargin=49, title=title, author='SIKORDIK RSBM')
    flow = []
    for i, p in enumerate(pages):
        if i: flow.append(PageBreak())
        flow.extend(parse_page(p))
    doc.build(flow, onFirstPage=footer, onLaterPages=footer)

source = (ROOT / 'docs/PANDUAN-PENGGUNA.md').read_text(encoding='utf-8')
pages = source.split('<!-- page -->')
build('Buku-Panduan-SIKORDIK.pdf', pages, 'Buku Panduan Penggunaan SIKORDIK')
probis = [pages[2].replace('2 Peta proses bisnis dari awal sampai akhir', 'Proses Bisnis SIKORDIK'),
          pages[1].replace('1 Mulai dari peran Anda', 'Pembagian Tugas dan Titik Mulai'),
          pages[16].replace('16 Checklist akhir dan penyelesaian', 'Pemeriksaan Akhir dan Penyelesaian')]
build('Alur-Proses-Bisnis-SIKORDIK.pdf', probis, 'Alur Proses Bisnis SIKORDIK')
(ROOT / 'docs/PROSES-BISNIS.md').write_text('\n\n<!-- page -->\n\n'.join(p.strip() for p in probis) + '\n', encoding='utf-8')
print('Created user guide and process guide in', OUT)

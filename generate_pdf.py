import os
import sys
import shutil
import fitz # PyMuPDF
from reportlab.lib.pagesizes import letter
from reportlab.lib import colors
from reportlab.lib.colors import HexColor
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, Image, KeepTogether, HRFlowable, PageBreak
)
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT, TA_RIGHT, TA_JUSTIFY
from reportlab.pdfgen import canvas

# Define Brand Colors
PRIMARY = HexColor("#002447")           # Deep Indigo / Midnight Navy
PRIMARY_CONTAINER = HexColor("#1B3A5F") # Darker Navy Container
SECONDARY = HexColor("#835500")         # Bronze/Gold Accent
GOLD_ACCENT = HexColor("#FEAE2C")       # Warm Amber Gold
BG_SURFACE = HexColor("#FCF9F8")        # Light warm off-white
TEXT_DARK = HexColor("#1B1C1C")         # Dark Charcoal Body
BORDER_COLOR = HexColor("#EAE7E7")      # Light border
LIGHT_BLUE_BG = HexColor("#F0F4F8")     # Light blue tint for feature cards

class NumberedCanvas(canvas.Canvas):
    """
    Two-pass canvas to dynamically compute and draw total page numbers and running headers/footers.
    """
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._saved_page_states = []

    def showPage(self):
        self._saved_page_states.append(dict(self.__dict__))
        self._startPage()

    def save(self):
        num_pages = len(self._saved_page_states)
        for state in self._saved_page_states:
            self.__dict__.update(state)
            self.draw_page_decorations(num_pages)
            super().showPage()
        super().save()

    def draw_page_decorations(self, page_count):
        self.saveState()
        page_width, page_height = letter
        
        # Running Header (Page 2+)
        if self._pageNumber > 1:
            # Top accent bar
            self.setFillColor(PRIMARY)
            self.rect(0, page_height - 10, page_width, 10, fill=True, stroke=False)
            self.setFillColor(GOLD_ACCENT)
            self.rect(0, page_height - 13, page_width, 3, fill=True, stroke=False)
            
            # Header text
            self.setFont("Helvetica-Bold", 8)
            self.setFillColor(PRIMARY)
            self.drawString(36, page_height - 25, "SCHOOLPILOT — PLATFORM FEATURE SPECIFICATION")
            self.setFont("Helvetica", 8)
            self.setFillColor(colors.HexColor("#555555"))
            self.drawRightString(page_width - 36, page_height - 25, "Cloud-Based K-12 School Management")
            
            # Header line
            self.setStrokeColor(BORDER_COLOR)
            self.setLineWidth(0.5)
            self.line(36, page_height - 30, page_width - 36, page_height - 30)

        # Running Footer (All Pages)
        self.setStrokeColor(BORDER_COLOR)
        self.setLineWidth(0.5)
        self.line(36, 38, page_width - 36, 38)
        
        self.setFillColor(GOLD_ACCENT)
        self.rect(0, 0, page_width, 4, fill=True, stroke=False)

        self.setFont("Helvetica", 8)
        self.setFillColor(colors.HexColor("#666666"))
        self.drawString(36, 25, "Confidential & Proprietary — SchoolPilot Management Platform")
        
        page_str = f"Page {self._pageNumber} of {page_count}"
        self.drawRightString(page_width - 36, 25, page_str)
        self.restoreState()


def create_feature_guide_pdf(filename):
    doc = SimpleDocTemplate(
        filename,
        pagesize=letter,
        leftMargin=36,
        rightMargin=36,
        topMargin=38,
        bottomMargin=46
    )
    
    styles = getSampleStyleSheet()
    
    # Custom Typography Styles
    title_style = ParagraphStyle(
        'DocTitle',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=22,
        leading=26,
        textColor=PRIMARY,
        alignment=TA_LEFT
    )
    
    h1_style = ParagraphStyle(
        'SectionH1',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=12,
        leading=15,
        textColor=PRIMARY,
        spaceBefore=10,
        spaceAfter=4,
        keepWithNext=True
    )
    
    body_style = ParagraphStyle(
        'BodyDark',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=8.5,
        leading=12.5,
        textColor=TEXT_DARK,
        spaceAfter=4,
        alignment=TA_LEFT
    )

    bullet_style = ParagraphStyle(
        'BulletDark',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=8.5,
        leading=12.5,
        textColor=TEXT_DARK,
        spaceAfter=2.5,
        leftIndent=10
    )

    box_text_style = ParagraphStyle(
        'BoxText',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=8.5,
        leading=12.5,
        textColor=PRIMARY
    )

    table_header_style = ParagraphStyle(
        'TableHeader',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=8.5,
        leading=11,
        textColor=colors.white,
        alignment=TA_CENTER
    )

    table_cell_style = ParagraphStyle(
        'TableCell',
        parent=styles['Normal'],
        fontName='Helvetica',
        fontSize=8,
        leading=11,
        textColor=TEXT_DARK,
        alignment=TA_LEFT
    )
    
    table_cell_bold = ParagraphStyle(
        'TableCellBold',
        parent=styles['Normal'],
        fontName='Helvetica-Bold',
        fontSize=8,
        leading=11,
        textColor=PRIMARY,
        alignment=TA_LEFT
    )

    story = []

    # ---------------------------------------------------------
    # BRAND BANNER & HEADER
    # ---------------------------------------------------------
    logo_path = os.path.abspath(r"c:\Users\Administrator\Desktop\school pilot\logos\school pilot main logo.png")
    
    if os.path.exists(logo_path):
        img = Image(logo_path, width=160, height=40)
        img.hAlign = 'LEFT'
        logo_cell = img
    else:
        logo_cell = Paragraph("<b>SCHOOLPILOT</b>", title_style)

    meta_text = Paragraph(
        "<font color='#002447'><b>PRODUCT FEATURE & ARCHITECTURE SPECIFICATION</b></font><br/>"
        "<font color='#835500' size=8>Cloud-Based K-12 School Management System</font><br/>"
        "<font color='#666666' size=7.5>Version 1.0 • Software-Only Infrastructure • Zero Hardware Required</font>",
        ParagraphStyle('MetaText', parent=styles['Normal'], alignment=TA_RIGHT)
    )
    
    top_table = Table([[logo_cell, meta_text]], colWidths=[220, 320])
    top_table.setStyle(TableStyle([
        ('VALIGN', (0,0), (-1,-1), 'MIDDLE'),
        ('LEFTPADDING', (0,0), (-1,-1), 0),
        ('RIGHTPADDING', (0,0), (-1,-1), 0),
        ('BOTTOMPADDING', (0,0), (-1,-1), 2),
    ]))
    story.append(top_table)
    story.append(HRFlowable(width="100%", thickness=2, color=GOLD_ACCENT, spaceBefore=3, spaceAfter=8))

    # Executive Overview Box
    exec_text = (
        "<b>Executive Overview:</b> SchoolPilot is a next-generation, cloud-based school management platform engineered specifically for private K-12 "
        "day schools in Nigeria. Operating strictly under a <b>Software-Only</b> architectural core, SchoolPilot eliminates "
        "expensive biometric scanners, RFID readers, and specialized hardware. It turns standard smartphones, browsers, "
        "and existing office PCs into an integrated hub for administration, AI-assisted teaching, parent engagement, "
        "computer-based testing (CBT), and automated fee processing."
    )
    exec_table = Table([[Paragraph(exec_text, box_text_style)]], colWidths=[540])
    exec_table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,-1), LIGHT_BLUE_BG),
        ('BOX', (0,0), (-1,-1), 0.75, PRIMARY_CONTAINER),
        ('LINELEFT', (0,0), (0,0), 3.5, GOLD_ACCENT),
        ('TOPPADDING', (0,0), (-1,-1), 6),
        ('BOTTOMPADDING', (0,0), (-1,-1), 6),
        ('LEFTPADDING', (0,0), (-1,-1), 10),
        ('RIGHTPADDING', (0,0), (-1,-1), 10),
    ]))
    story.append(exec_table)
    story.append(Spacer(1, 6))

    # ---------------------------------------------------------
    # SECTION 1: ZERO-HARDWARE & SOFTWARE-ONLY ARCHITECTURE
    # ---------------------------------------------------------
    story.append(Paragraph("1. Zero-Hardware & Software-Only Architecture", h1_style))
    story.append(HRFlowable(width="100%", thickness=0.5, color=PRIMARY, spaceBefore=1, spaceAfter=5))
    
    sec1_intro = (
        "Traditional school management platforms require costly biometric readers, facial scanners, and smart cards. "
        "SchoolPilot completely replaces hardware procurement with standard mobile and desktop web capabilities:"
    )
    story.append(Paragraph(sec1_intro, body_style))

    features_s1 = [
        ("QR Code Camera Attendance", "Teachers and gatekeepers scan student badges or mobile passes directly using the built-in smartphone camera view for real-time attendance logging."),
        ("GPS Geofenced Staff Clock-In", "Staff log arrival and departure via smartphone GPS verification, enforcing authentic clock-ins strictly within school premises."),
        ("Real-Time Bus Location Broadcasting", "Bus drivers run the mobile app during active routes, allowing parents to track bus arrival live without vehicle GPS tracker hardware."),
        ("Phone Camera Library Barcoding", "Library check-in and checkout uses the phone camera to scan standard book barcodes, removing the need for handheld barcode guns."),
        ("Printable Student & Staff ID Cards", "Generates high-resolution, print-ready PDF ID cards complete with QR codes and school branding, printable on standard office printers."),
        ("Resilient Offline Desktop CBT", "Lightweight desktop app for CBT exams that runs without internet during outages and syncs scores automatically when reconnected.")
    ]

    for title, desc in features_s1:
        item_text = f"<b>• {title}:</b> {desc}"
        story.append(Paragraph(item_text, bullet_style))

    story.append(Spacer(1, 6))

    # ---------------------------------------------------------
    # SECTION 2: CORE ACADEMIC & ADMINISTRATIVE MANAGEMENT
    # ---------------------------------------------------------
    story.append(Paragraph("2. Core Academic & Administrative Management (K-12 Focused)", h1_style))
    story.append(HRFlowable(width="100%", thickness=0.5, color=PRIMARY, spaceBefore=1, spaceAfter=5))

    sec2_intro = (
        "Designed specifically around Nigerian K-12 academic structures (Sessions, Terms, Classes, Arms/Streams) "
        "rather than generic higher-education faculty concepts."
    )
    story.append(Paragraph(sec2_intro, body_style))

    features_s2 = [
        ("Nigerian Academic Structure Native", "Full support for 3-Term sessions, Continuous Assessment (CA) weightings, Termly Broadsheets, JSS/SS divisions, and national curriculum standards (WAEC, NECO, JAMB)."),
        ("Student Information System (SIS)", "Centralized digital student dossiers tracking profiles, guardian linkages, medical notes, academic history, and NDPA 2023 compliant data protection."),
        ("Automated Conflict-Free Timetabling", "Intelligent schedule generator balancing subject periods, teacher availability, and classroom allocations without scheduling overlaps."),
        ("Unified Multi-Tenant Web & Mobile App", "Modern Next.js web dashboard and Flutter cross-platform mobile app providing role-optimized access for Admins, Teachers, Students, and Parents.")
    ]

    for title, desc in features_s2:
        item_text = f"<b>• {title}:</b> {desc}"
        story.append(Paragraph(item_text, bullet_style))

    story.append(Spacer(1, 6))

    # ---------------------------------------------------------
    # SECTION 3: AI-POWERED TEACHING & LEARNING ECOSYSTEM
    # ---------------------------------------------------------
    story.append(Paragraph("3. AI-Powered Teaching & Learning Ecosystem", h1_style))
    story.append(HRFlowable(width="100%", thickness=0.5, color=PRIMARY, spaceBefore=1, spaceAfter=5))

    features_s3 = [
        ("AI Teacher Copilot", "Generates comprehensive lesson plans, practice worksheets, and assignment quizzes tailored to specific term topics and curriculum goals in seconds."),
        ("AI Report Card Remarks with Human Oversight", "Generates personalized, constructive report card remarks for every student. AI comments stay in <i>pending_approval</i> status until reviewed and approved by the teacher."),
        ("24/7 AI Student Curriculum Tutor", "Interactive personal AI assistant calibrated strictly to the school's actual syllabus and teacher-assigned coursework for safe round-the-clock learning support."),
        ("Admin AI Risk & Revenue Insights", "Automated predictive alerts for school heads identifying students showing sudden score drops, attendance anomalies, or tuition delinquency risks.")
    ]

    for title, desc in features_s3:
        item_text = f"<b>• {title}:</b> {desc}"
        story.append(Paragraph(item_text, bullet_style))

    # CLEAN PAGE BREAK BEFORE SECTION 4 TO PERFECTLY BALANCE PAGES
    story.append(PageBreak())

    # ---------------------------------------------------------
    # SECTION 4: COMPUTER-BASED TESTING (CBT) & ASSESSMENTS
    # ---------------------------------------------------------
    story.append(Paragraph("4. Computer-Based Testing (CBT) & Assessment Engine", h1_style))
    story.append(HRFlowable(width="100%", thickness=0.5, color=PRIMARY, spaceBefore=1, spaceAfter=5))

    features_s4 = [
        ("Rich Media & LaTeX Math Questions", "Supports multiple choice, true/false, and theory questions enriched with images and inline LaTeX math formulas rendered offline via bundled KaTeX."),
        ("Anti-Cheat & Exam Hall Controls", "Question randomization, option shuffling, strict timed countdowns, browser focus loss monitoring, and instant automated grading."),
        ("WAEC/JAMB Standardized Practice", "Pre-loaded question banks allowing students to simulate national standardized examinations under authentic timed exam hall conditions.")
    ]

    for title, desc in features_s4:
        item_text = f"<b>• {title}:</b> {desc}"
        story.append(Paragraph(item_text, bullet_style))

    story.append(Spacer(1, 8))

    # ---------------------------------------------------------
    # SECTION 5: FEE MANAGEMENT & FINANCIAL INTEGRATION
    # ---------------------------------------------------------
    story.append(Paragraph("5. Fee Management & Financial Integration", h1_style))
    story.append(HRFlowable(width="100%", thickness=0.5, color=PRIMARY, spaceBefore=1, spaceAfter=5))

    features_s5 = [
        ("Integrated Payment Gateways", "Native integration with Paystack and Flutterwave enabling tuition payment via Debit Card, Bank Transfer, or USSD with automated instant receipts."),
        ("Parent Financial Ledger & Invoicing", "Real-time itemized breakdown of tuition, uniforms, bus services, and extracurricular fees with partial payment support and clear balance tracking."),
        ("WhatsApp & SMS Communication Hub", "Automated payment reminders, attendance alerts, exam results, and school announcements sent directly to parents' mobile phones.")
    ]

    for title, desc in features_s5:
        item_text = f"<b>• {title}:</b> {desc}"
        story.append(Paragraph(item_text, bullet_style))

    story.append(Spacer(1, 8))

    # ---------------------------------------------------------
    # SECTION 6: USER ROLES & SYSTEM PERMISSIONS MATRIX
    # ---------------------------------------------------------
    story.append(Paragraph("6. User Roles & System Permissions Matrix", h1_style))
    story.append(HRFlowable(width="100%", thickness=0.5, color=PRIMARY, spaceBefore=1, spaceAfter=5))

    matrix_data = [
        [
            Paragraph("<b>Role</b>", table_header_style),
            Paragraph("<b>Primary Responsibilities & Access Scope</b>", table_header_style),
            Paragraph("<b>Key Features Available</b>", table_header_style)
        ],
        [
            Paragraph("Super Admin", table_cell_bold),
            Paragraph("Platform operators & system maintenance", table_cell_style),
            Paragraph("Multi-school onboarding, platform security, subscription billing, cross-school analytics", table_cell_style)
        ],
        [
            Paragraph("School Admin", table_cell_bold),
            Paragraph("Principal, Proprietor, & Front Office", table_cell_style),
            Paragraph("Staff/Student records, fee structures, timetables, broadsheet approval, AI insight dashboard", table_cell_style)
        ],
        [
            Paragraph("Teacher", table_cell_bold),
            Paragraph("Academic staff & Subject Instructors", table_cell_style),
            Paragraph("Attendance scanning, CA grading, AI lesson planner, AI report card remark approval", table_cell_style)
        ],
        [
            Paragraph("Student", table_cell_bold),
            Paragraph("Enrolled learners (JSS1 - SS3)", table_cell_style),
            Paragraph("CBT exam portal, assignment submission, term results view, 24/7 AI curriculum tutor", table_cell_style)
        ],
        [
            Paragraph("Parent", table_cell_bold),
            Paragraph("Guardians & Sponsors", table_cell_style),
            Paragraph("Online fee payments, real-time bus tracking, child attendance, report cards, teacher chat", table_cell_style)
        ]
    ]

    matrix_table = Table(matrix_data, colWidths=[85, 205, 250])
    matrix_table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,0), PRIMARY),
        ('ALIGN', (0,0), (-1,0), 'CENTER'),
        ('VALIGN', (0,0), (-1,-1), 'MIDDLE'),
        ('GRID', (0,0), (-1,-1), 0.5, BORDER_COLOR),
        ('TOPPADDING', (0,0), (-1,-1), 4.5),
        ('BOTTOMPADDING', (0,0), (-1,-1), 4.5),
        ('LEFTPADDING', (0,0), (-1,-1), 6),
        ('RIGHTPADDING', (0,0), (-1,-1), 6),
        ('ROWBACKGROUNDS', (0,1), (-1,-1), [colors.white, BG_SURFACE])
    ]))
    
    story.append(matrix_table)
    story.append(Spacer(1, 12))

    # ---------------------------------------------------------
    # CALL TO ACTION BANNER
    # ---------------------------------------------------------
    cta_title_style = ParagraphStyle('CTATitle', parent=styles['Normal'], fontName='Helvetica-Bold', fontSize=11, leading=14, textColor=colors.white, alignment=TA_CENTER)
    cta_body_style = ParagraphStyle('CTABody', parent=styles['Normal'], fontName='Helvetica', fontSize=8.5, leading=12, textColor=GOLD_ACCENT, alignment=TA_CENTER)

    cta_content = [
        Paragraph("READY TO TRANSFORM YOUR SCHOOL MANAGEMENT?", cta_title_style),
        Spacer(1, 2),
        Paragraph("Zero hardware cost • Free core modules • Onboard in 30 minutes via WhatsApp", cta_body_style)
    ]
    
    cta_table = Table([[cta_content]], colWidths=[540])
    cta_table.setStyle(TableStyle([
        ('BACKGROUND', (0,0), (-1,-1), PRIMARY_CONTAINER),
        ('BOX', (0,0), (-1,-1), 1, GOLD_ACCENT),
        ('TOPPADDING', (0,0), (-1,-1), 9),
        ('BOTTOMPADDING', (0,0), (-1,-1), 9),
        ('LEFTPADDING', (0,0), (-1,-1), 10),
        ('RIGHTPADDING', (0,0), (-1,-1), 10),
        ('ALIGN', (0,0), (-1,-1), 'CENTER')
    ]))
    story.append(KeepTogether(cta_table))

    # Build Document
    doc.build(story, canvasmaker=NumberedCanvas)
    print(f"PDF successfully regenerated at: {filename}")

if __name__ == "__main__":
    out_pdf = os.path.abspath("SchoolPilot_Platform_Features.pdf")
    create_feature_guide_pdf(out_pdf)

    # Copy to artifact directory and render page images
    artifact_dir = r"C:\Users\Administrator\.gemini\antigravity\brain\d74b51c2-4a18-4552-81e7-c72db97b99f4"
    pdf_dest = os.path.join(artifact_dir, "SchoolPilot_Platform_Features.pdf")
    shutil.copy(out_pdf, pdf_dest)

    doc = fitz.open(out_pdf)
    print(f"New PDF Page Count: {len(doc)}")

    # Clean old images
    for i in range(1, 10):
        old_img = os.path.join(artifact_dir, f"page_{i}.png")
        if os.path.exists(old_img):
            try:
                os.remove(old_img)
            except:
                pass

    for i, page in enumerate(doc):
        pix = page.get_pixmap(dpi=150)
        img_path = os.path.join(artifact_dir, f"page_{i+1}.png")
        pix.save(img_path)
        print(f"Rendered Page {i+1} -> {img_path}")

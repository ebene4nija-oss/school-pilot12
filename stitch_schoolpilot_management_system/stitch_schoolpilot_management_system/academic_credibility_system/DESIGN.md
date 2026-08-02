---
name: Academic Credibility System
colors:
  surface: '#fcf9f8'
  surface-dim: '#dcd9d9'
  surface-bright: '#fcf9f8'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f6f3f2'
  surface-container: '#f0eded'
  surface-container-high: '#eae7e7'
  surface-container-highest: '#e5e2e1'
  on-surface: '#1b1c1c'
  on-surface-variant: '#43474e'
  inverse-surface: '#303030'
  inverse-on-surface: '#f3f0ef'
  outline: '#74777f'
  outline-variant: '#c3c6cf'
  surface-tint: '#436087'
  primary: '#002447'
  on-primary: '#ffffff'
  primary-container: '#1b3a5f'
  on-primary-container: '#88a4cf'
  inverse-primary: '#abc8f5'
  secondary: '#835500'
  on-secondary: '#ffffff'
  secondary-container: '#feae2c'
  on-secondary-container: '#6b4500'
  tertiary: '#002b12'
  on-tertiary: '#ffffff'
  tertiary-container: '#004320'
  on-tertiary-container: '#4bb771'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d4e3ff'
  primary-fixed-dim: '#abc8f5'
  on-primary-fixed: '#001c39'
  on-primary-fixed-variant: '#2a486e'
  secondary-fixed: '#ffddb4'
  secondary-fixed-dim: '#ffb955'
  on-secondary-fixed: '#291800'
  on-secondary-fixed-variant: '#633f00'
  tertiary-fixed: '#8df9ac'
  tertiary-fixed-dim: '#71dc92'
  on-tertiary-fixed: '#00210d'
  on-tertiary-fixed-variant: '#005229'
  background: '#fcf9f8'
  on-background: '#1b1c1c'
  surface-variant: '#e5e2e1'
typography:
  display-lg:
    fontFamily: Work Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  display-lg-mobile:
    fontFamily: Work Sans
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
  headline-md:
    fontFamily: Work Sans
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 26px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 22px
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.05em
  data-mono:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 20px
    letterSpacing: -0.01em
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 8px
  xs: 4px
  sm: 12px
  md: 16px
  lg: 24px
  xl: 32px
  gutter: 16px
  margin-mobile: 16px
  margin-desktop: 48px
---

## Brand & Style
The design system is engineered for the Nigerian private education sector, prioritizing institutional trust, clarity, and high-performance utility. The brand personality is authoritative yet supportive, functioning as a reliable digital backbone for administrators, teachers, and parents.

The visual style follows a **Modern Corporate** aesthetic with a heavy emphasis on **High-Contrast Functionalism**. Given the target hardware (mid-range Android devices) and environmental factors (high-glare outdoor use), the system rejects subtle gradients and soft shadows in favor of crisp boundaries, legible type scales, and a robust color hierarchy. The emotional response should be one of "calm control"—reducing the cognitive load of complex data management like broadsheets and termly assessments.

## Colors
The palette is anchored by institutional stability and high-action visibility.

- **Primary (#1B3A5F):** A deep, scholarly blue used for headers, navigation, and primary branding. It establishes credibility.
- **Accent (#F5A623):** Amber/Gold reserved strictly for high-priority calls to action (CTAs) and "Primary" buttons to ensure they stand out against the blue.
- **Semantic Green (#2E9E5B):** Used for "Paid" statuses, "Present" attendance, and successful grade entries.
- **Semantic Red (#D64545):** Used for "Overdue" fees, "Absent" logs, and critical alerts.
- **Surface:** An off-white background (#F8F9FA) reduces eye strain while maintaining high contrast against the dark charcoal text (#222222).

## Typography
Typography is optimized for legibility in data-dense environments like broadsheets and report cards. 

**Work Sans** is used for headings to provide a grounded, professional feel. **Inter** is used for all body text and UI labels due to its exceptional tall x-height and readability on mobile screens. 

- **Hierarchy:** Use `display-lg` for dashboard summaries (e.g., Total Enrollment).
- **Line Height:** Generous line heights (1.5x+) are applied to body text to prevent "crowding" when viewing long lists of student names or Continuous Assessment (CA) scores.
- **Minimum Size:** No functional text should fall below 14px on mobile devices to ensure accessibility for all staff members.

## Layout & Spacing
The design system utilizes a **8px linear scale** for consistent rhythm. 

- **Mobile First:** A 4-column fluid grid is used for mobile (Android-standard), transitioning to a 12-column fixed grid on desktop.
- **Touch Targets:** All interactive elements (buttons, checkboxes) must maintain a minimum 48x48px tap area, even if the visual representation is smaller.
- **Density:** While whitespace is generous in information pages, data tables (e.g., Grade Entries) may use a "Compact" 4px spacing model to ensure more columns (CA1, CA2, Exam, Total) are visible without excessive horizontal scrolling.

## Elevation & Depth
In this design system, depth is communicated through **Tonal Layering** and **Low-Contrast Outlines** rather than heavy shadows, to ensure UI elements remain distinct on lower-quality LCD screens.

- **Level 0 (Background):** #F8F9FA.
- **Level 1 (Cards/Containers):** White (#FFFFFF) with a 1px border of #E2E8F0. This is the primary surface for student profiles and grade inputs.
- **Level 2 (Interactive/Floating):** Use a subtle, large-radius shadow (0px 4px 12px rgba(0,0,0,0.05)) for elements that require focus, such as modals or floating action buttons (FABs).
- **No Glassmorphism:** Avoid transparency and blur effects to maintain performance and contrast integrity.

## Shapes
The shape language balances modern approachability with institutional structure. 

- **Standard Radius:** 8px is the default for buttons, input fields, and small cards. 
- **Large Radius:** 16px (rounded-lg) is used for major container sections and dashboard widgets.
- **Interactive States:** Use sharp, 1px strokes for focused input fields in the Primary Blue (#1B3A5F) to provide clear visual feedback during data entry.

## Components
Consistent component behavior is critical for multi-user school environments.

- **Buttons:** 
  - **Primary:** Amber (#F5A623) with dark text (#222222) for high-contrast visibility.
  - **Secondary:** Primary Blue (#1B3A5F) with white text.
  - **Ghost:** 1px Primary Blue border for neutral actions like "Cancel" or "Go Back."
- **Data Cards:** Essential for student lists. Cards should feature a 4px left-border accent color to denote status (e.g., Red for "Fee Owed," Green for "Paid").
- **Input Fields:** Use "Floating Labels" to save vertical space. Labels must remain visible after text is entered.
- **Status Chips:** Small, high-contrast badges for terms like "JSS1," "SS2," or "WAEC Candidate." Use light background tints of the semantic colors (e.g., Light Green background with Dark Green text).
- **Currency Display:** All Naira values must use the ₦ symbol explicitly, formatted to 2 decimal places in mono-spaced Inter (e.g., ₦50,000.00).
- **Broadsheet Tables:** Sticky headers for student names and fixed-width columns for numerical CA scores to ensure alignment during rapid entry.
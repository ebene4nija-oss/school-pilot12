---
name: High-Contrast Functionalism
colors:
  surface: '#fcf9f8'
  surface-dim: '#dcd9d8'
  surface-bright: '#fcf9f8'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f6f3f2'
  surface-container: '#f0edec'
  surface-container-high: '#eae7e7'
  surface-container-highest: '#e4e2e1'
  on-surface: '#1b1c1b'
  on-surface-variant: '#43474e'
  inverse-surface: '#303030'
  inverse-on-surface: '#f3f0ef'
  outline: '#74777f'
  outline-variant: '#c3c6cf'
  surface-tint: '#446086'
  primary: '#000e22'
  on-primary: '#ffffff'
  primary-container: '#002447'
  on-primary-container: '#718cb5'
  inverse-primary: '#acc8f4'
  secondary: '#835500'
  on-secondary: '#ffffff'
  secondary-container: '#ffc16a'
  on-secondary-container: '#784d00'
  tertiary: '#001105'
  on-tertiary: '#ffffff'
  tertiary-container: '#002a11'
  on-tertiary-container: '#6a9472'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d4e3ff'
  primary-fixed-dim: '#acc8f4'
  on-primary-fixed: '#001c39'
  on-primary-fixed-variant: '#2c486d'
  secondary-fixed: '#ffddb4'
  secondary-fixed-dim: '#f9bb65'
  on-secondary-fixed: '#291800'
  on-secondary-fixed-variant: '#633f00'
  tertiary-fixed: '#c0eec7'
  tertiary-fixed-dim: '#a5d1ac'
  on-tertiary-fixed: '#00210c'
  on-tertiary-fixed-variant: '#274f32'
  background: '#fcf9f8'
  on-background: '#1b1c1b'
  surface-variant: '#e4e2e1'
typography:
  headline-lg:
    fontFamily: Work Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-lg-mobile:
    fontFamily: Work Sans
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
  headline-md:
    fontFamily: Work Sans
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
  headline-sm:
    fontFamily: Work Sans
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-lg:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  unit: 8px
  screen-margin: 16px
  gutter: 16px
  stack-sm: 8px
  stack-md: 16px
  stack-lg: 24px
---

## Brand & Style
This design system is built on the principles of **Modern Corporate Functionalism**. It is tailored for the high-stakes environment of K-12 school management, prioritizing authority, clarity, and rapid information retrieval. 

The aesthetic is defined by "Crisp Boundaries"—avoiding all soft shadows, gradients, and transparency in favor of 1px solid outlines and distinct tonal layering. This creates a UI that feels reliable and physically structured, mirroring the administrative rigor of top-tier educational institutions. The emotional response is one of organized support; the system does not fade into the background but stands as a robust framework for complex data.

## Colors
The palette utilizes deep, authoritative tones contrasted against high-visibility action colors. 

- **Primary (#002447):** Used for structural navigation and core branding elements.
- **Secondary/Action (#FEAE2C/835500):** The amber secondary color is reserved for primary calls to action, ensuring high visibility against the deep navy primary.
- **Surface Strategy:** The system uses `#fcf9f8` as the base background. Depth is achieved not through shadows, but by layering white (`#ffffff`) surfaces with 1px borders.
- **Functional Tones:** Success, Danger, and Warning colors are applied at high saturation for immediate recognition in status tracking and grade reporting.

## Typography
Typography is optimized for legibility in data-dense environments. 

**Work Sans** is used for all headings to provide a professional, grounded, and slightly industrial feel. Weights are kept heavy (600-700) to ensure high contrast against surfaces.

**Inter** is the workhorse for all body copy, labels, and data points. Its neutral, systematic nature ensures that long lists of student names or financial figures remain readable. 

For mobile, scale headlines down as defined in the tokens to maintain screen real estate while keeping the "bold" hierarchy intact.

## Layout & Spacing
The system follows a strict 8px linear scale. All padding, margins, and gaps must be multiples of 8. 

- **Grid:** A 12-column fluid grid is used for desktop (breakpoint 1440px), transitioning to 8 columns for tablet (768px) and 4 columns for mobile (375px).
- **Margins:** A standard 16px safe area is maintained on all mobile screens.
- **Alignment:** Content should prioritize vertical stack alignment to mimic paper-based school records, providing a familiar mental model for administrative staff.

## Elevation & Depth
Depth is strictly non-optical. This design system rejects shadows and blurs.

1.  **Level 0 (Background):** `#fcf9f8`
2.  **Level 1 (Cards/Sections):** White `#ffffff` surface with a 1px `#c3c6cf` solid border.
3.  **Tonal Stacking:** For nested elements (like a list item inside a card), use a subtle tonal shift (e.g., `#f1f3f8`) rather than a shadow to indicate a new layer.
4.  **Active State:** Use the primary blue (`#002447`) as a 2px stroke to indicate focus or selection.

## Shapes
The shape language is controlled and systematic. While we use a "Soft" foundation (0.25rem), we implement specific overrides for structural components:

- **Cards & Inputs:** 8px radius.
- **Main Containers/Sections:** 16px radius to create a clear "macro" hierarchy.
- **Buttons & Chips:** Fully rounded (pill-shaped) to distinguish interactive elements from static data containers.

## Components
- **Primary Button:** 48dp height. Background: `#feae2c`, Text: `#1b1c1c` (Bold). No shadow. Fully rounded.
- **Secondary Button:** Background: `#002447`, Text: `#ffffff`. Fully rounded.
- **Ghost Button:** Transparent fill, 1px `#002447` border.
- **Data Cards:** White fill with 1px `#c3c6cf` border. To indicate status (e.g., "Paid", "Absent", "Urgent"), apply a 4px solid vertical border to the left edge using the functional color palette.
- **Status Chips:** Light tint background (10% opacity of status color) with high-contrast dark text of the same hue. 
- **Input Fields:** Floating label style. 1px border. On focus, the border increases to 2px in Primary Blue (`#002447`) with the label shrinking to the top.
- **Icons:** Use *Material Symbols Outlined* at 20px or 24px, ensuring line weights remain consistent with the 1px UI borders.
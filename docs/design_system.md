---
name: KelolaKos Design System
colors:
  surface: '#f1fbff'
  surface-dim: '#d1dce0'
  surface-bright: '#f1fbff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#eaf5fa'
  surface-container: '#e4f0f4'
  surface-container-high: '#dfeaef'
  surface-container-highest: '#d9e4e9'
  on-surface: '#131d21'
  on-surface-variant: '#474554'
  inverse-surface: '#283236'
  inverse-on-surface: '#e7f3f7'
  outline: '#787586'
  outline-variant: '#c8c4d7'
  surface-tint: '#5847d2'
  primary: '#5341cd'
  on-primary: '#ffffff'
  primary-container: '#6c5ce7'
  on-primary-container: '#faf6ff'
  inverse-primary: '#c6bfff'
  secondary: '#006b55'
  on-secondary: '#ffffff'
  secondary-container: '#6dfad2'
  on-secondary-container: '#00725b'
  tertiary: '#993a24'
  on-tertiary: '#ffffff'
  tertiary-container: '#b95239'
  on-tertiary-container: '#fff6f4'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#e4dfff'
  primary-fixed-dim: '#c6bfff'
  on-primary-fixed: '#160066'
  on-primary-fixed-variant: '#4029ba'
  secondary-fixed: '#6dfad2'
  secondary-fixed-dim: '#4bddb7'
  on-secondary-fixed: '#002018'
  on-secondary-fixed-variant: '#005140'
  tertiary-fixed: '#ffdad2'
  tertiary-fixed-dim: '#ffb4a3'
  on-tertiary-fixed: '#3d0700'
  on-tertiary-fixed-variant: '#812914'
  background: '#f1fbff'
  on-background: '#131d21'
  surface-variant: '#d9e4e9'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Inter
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
  label-md:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 18px
    letterSpacing: 0.05em
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
  headline-md-mobile:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  sidebar-width: 260px
  topbar-height: 72px
  gutter: 24px
  container-padding: 32px
  stack-sm: 8px
  stack-md: 16px
  stack-lg: 24px
---

## Brand & Style
The design system focuses on efficiency, reliability, and modern property management. It targets property owners and managers who require a high-density information environment that remains legible and stress-free. 

The aesthetic is **Corporate Modern** with a focus on functional clarity. It utilizes a predominantly white and light gray canvas to let data and status indicators stand out. The interface communicates "order" and "professionalism" through consistent alignment, ample white space within components, and soft edges that reduce visual fatigue during long periods of administrative use.

## Colors
The color palette is designed for high-signal data visualization.
- **Primary (Purple):** Used for primary actions, active navigation states, and branding accents.
- **Success (Green):** Indicates occupied units, completed payments, and healthy growth metrics.
- **Warning (Orange):** Highlights upcoming expirations or pending maintenance requests.
- **Danger (Red):** Reserved for overdue payments, emergency alerts, and destructive actions.
- **Neutral/Surface:** A range of grays from `#F9FAFB` (page background) to `#FFFFFF` (card/sidebar surfaces) ensures a clear distinction between the canvas and interactive elements.

## Typography
This design system utilizes **Inter** exclusively to leverage its exceptional legibility in UI environments.
- **Headlines:** Bold weights with slight negative letter-spacing to create a "tight" professional feel for dashboard titles and metric summaries.
- **Body:** Standardized at 14px for data density without sacrificing readability.
- **Labels:** Uppercase styles are used for sidebar section headers and table column headers to create a distinct hierarchy between structural labels and dynamic content.

## Layout & Spacing
The layout follows a **Fixed Sidebar** model for constant navigation access.
- **Sidebar:** 260px width, fixed to the left, using a white background to separate it from the light gray main content area.
- **Topbar:** 72px height, containing global search, notification triggers, and user profile. It should remain sticky.
- **Main Content:** Utilizes a fluid grid within a max-width container of 1440px for wide screens. 
- **Rhythm:** An 8px base grid governs all spacing. Summary cards should span 3 columns in a 12-column grid (4 cards per row) on desktop, reflowing to 1 or 2 columns on mobile.

## Elevation & Depth
Depth is created through **Ambient Shadows** and tonal separation rather than heavy borders.
- **Level 0 (Background):** `#F9FAFB` – The base canvas.
- **Level 1 (Surface):** `#FFFFFF` – Sidebar and Main Cards. Use a soft shadow: `0px 4px 12px rgba(0, 0, 0, 0.05)`.
- **Level 2 (Interactive):** Hover states for cards or dropdowns. Use a slightly deeper shadow: `0px 8px 24px rgba(0, 0, 0, 0.08)`.
- **Modals:** High elevation with `0px 20px 48px rgba(0, 0, 0, 0.12)` and a 40% opacity neutral-dark overlay.

## Shapes
The shape language is friendly yet structured.
- **Components:** Buttons and input fields use a `0.5rem` (8px) radius.
- **Containers:** Summary cards and the main sidebar container use `rounded-lg` (16px) to emphasize the "clean dashboard" look.
- **Badges:** Use a pill-shaped `rounded-xl` (24px+) for status indicators to distinguish them from interactive buttons.

## Components
- **Summary Cards:** Feature a subtle 10% opacity gradient of the primary color in the top-right corner. Large numeric values use `headline-md`.
- **Status Badges:** Use a "soft tint" approach (e.g., Success badge has `#00B894` text on a 10% opacity green background) for a professional, non-aggressive look.
- **Sidebar:** Section labels (e.g., "MANAGEMENT", "REPORTS") use `label-md` with low-contrast gray. Active items use the primary purple for the icon and text, with a 4px vertical bar on the far left.
- **Tables:** No vertical borders. Use 1px light gray horizontal dividers. Rows should have a subtle background color change on hover.
- **Input Fields:** White background with a 1px gray border. On focus, transition to a primary purple border with a 3px soft purple outer glow.
- **Buttons:** Primary buttons use a solid primary purple. Secondary buttons use a white background with a gray border. Both should have a subtle `0.5s` transition on hover.

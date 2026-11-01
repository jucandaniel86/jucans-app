Jucans — Global UI/UX Design Guidelines

These rules apply to ALL future frontend implementations, redesigns and visual modifications.

Core principle: Compact by Default

Jucans is a mobile-first productivity application.

Prioritize information density, usability and visual clarity over decorative spacing.

The interface must be clean and modern, but never unnecessarily spacious.

Compact does not mean cramped. Maintain readability and comfortable touch interactions while eliminating wasted space.

Layout and Spacing

* Use compact vertical spacing between sections.
* Avoid excessive padding inside cards, forms, modals and list items.
* Avoid large empty areas unless they serve a clear functional purpose.
* Prefer content-driven heights over fixed heights.
* Avoid oversized headers and section titles.
* Use consistent spacing based on the existing design tokens.
* Do not introduce arbitrary large margins.
* Prefer compact rows over vertically stacked elements when screen width allows.
* Group closely related controls on the same row whenever practical.

Suggested spacing guidelines

Use existing design tokens where available.

Otherwise, prefer:

* Between related elements: 4–8px.
* Between form fields: 10–14px.
* Between sections: 16–20px.
* Card padding: 12–16px.
* Modal body padding: 12–16px on mobile.
* Compact headers: approximately 44–52px.

These are guidelines, not rigid constraints.

Forms

* Prefer concise labels.
* Avoid permanently displaying long helper descriptions.
* Use tooltips, expandable help or contextual explanations when necessary.
* Place related fields horizontally when practical.
* Use collapsible sections for advanced or rarely used settings.
* Avoid large textareas unless the content genuinely requires them.
* Keep action buttons compact and clearly positioned.
* Do not create excessive spacing between labels and inputs.
* Avoid repeating information already obvious from the interface.

Cards and Lists

* Prefer compact cards with clear information hierarchy.
* Avoid displaying every property on a separate line.
* Use small badges, icons and secondary text for metadata.
* Keep list rows visually efficient.
* Avoid large empty-state illustrations.
* Do not use oversized icons or decorative graphics without functional value.

Modals

* Prefer content-driven height with a maximum viewport constraint.
* Do not stretch short forms to fill the screen on desktop.
* Fullscreen mobile editors are acceptable when justified by complex interactions.
* Keep headers and footers compact.
* Avoid unnecessary nested containers and excessive padding.

Mobile Requirements

* Optimize primarily for iPhone screens.
* Minimize unnecessary vertical scrolling.
* Keep frequently used controls easy to reach.
* Preserve touch targets of approximately 44px where appropriate.
* Maintain input font sizes of at least 16px on iOS to prevent automatic zoom.
* Avoid horizontal overflow.
* Respect safe areas.

Visual Hierarchy

Use typography, subtle borders and spacing to establish hierarchy.

Do not rely on large blank spaces to separate content.

Prefer:

* Compact section headings.
* Clear labels.
* Subtle separators.
* Small status indicators.
* Consistent alignment.

Avoid Overengineering

Do not introduce unnecessary:

* Wrapper components.
* Nested cards.
* Decorative containers.
* Explanatory paragraphs.
* Placeholder sections.
* Oversized empty states.
* Multiple levels of headings.

Every visual element should serve a clear purpose.

Implementation Requirements

Before finalizing any UI implementation:

1. Review the layout for unnecessary whitespace.
2. Check whether related controls can share a row.
3. Remove redundant labels and helper text.
4. Verify compactness at 320px and 390px viewport widths.
5. Verify that desktop layouts do not become unnecessarily stretched.
6. Ensure compactness does not compromise accessibility or usability.

Final Design Rule

When choosing between two equally usable layouts, prefer the more compact one.

The goal is a polished, efficient interface that lets users accomplish tasks quickly, without excessive scrolling or visual clutter.
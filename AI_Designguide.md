use Tailwind CSS for styling.
use Lucide Icons for icons.
if JavaScript is necessary, use JQuery.

UX is important. The user should be able to quickly navigate Portflow, make changes, and instantly see important information. Use colors, icons, tooltips, and compressed information where helpful.

The UI should be minimalistic, with small animations only in a later phase.

Prepare the Tailwind setup for multiple themes, fonts, font sizes, and languages.

Use whitespace, subtle shadows, and flat colors for non-interactive areas. Interactive elements should stand out clearly.

Typography rules:
- H1: `text-2xl font-bold`
- H2: `text-lg font-bold`
- Text: browser default
- Small text / helper text: `text-sm text-gray-600`
- User-adjustable typography must use custom utility classes backed by CSS variables instead of hard-coded Tailwind font classes, so font family and font size can be changed dynamically in Settings.
- The font-size setting should scale the root UI size so tables, cards, labels, and controls all change together.

Theme rules:
- Default themes are Light and Dark.
- A high-contrast theme may be added later.
- Theme colors should be mapped through reusable variables / classes, not hard-coded into each component.
- Dark mode should stay neutral and contrast-driven, with dark charcoal surfaces and readable gray text rather than a blue-tinted wash.

Color semantics:
- Green: success, save, execute.
- Orange: edit, hint, count badge.
- Red: delete, warning.
- Blue: search, call-to-action, confirmation, default primary action.
- Gray: disabled, unused, inactive.

Buttons:
- Use icon-only buttons by default.
- Add text only if the action would otherwise be unclear, for example “Alle ausführen”.
- Use `title` as the tooltip.
- Button styling should follow the icon button pattern, for example:
	`<button class="h-10 w-10 rounded-full bg-yellow-400 hover:bg-yellow-600 text-white flex items-center justify-center" title="Info"><i data-lucide="info"></i></button>`

Layout rules:
- The header must always contain Logo, Navigation Bar, Logout button, and Settings.
- The main screen can use one area or two areas.
- If split, the left side is module navigation.
- Deeper module navigation should live in the left sidebar as subitems and stay hidden until the module is opened.
- The active menu and current module must always be obvious.
- Border radius should be present on every relevant element.
- The left sidebar should default to a compact width and support an icon-only collapsed mode on desktop.
- Desktop should provide a clear toggle to switch between icon-only and full sidebar labels.
- On mobile, the sidebar should become a drawer/overlay instead of squeezing the content area.
- On mobile, header navigation and controls should compress cleanly before they wrap awkwardly.
- Mobile header should use a dedicated layout with only logo and menu button; module links plus Settings/Logout should be inside the mobile drawer.
- Responsive visibility rules must ensure mobile and desktop header variants are mutually exclusive to prevent duplicate logos or controls.
- The main navigation must remain visually centered in the header, independent of logo width.
- The header should keep the Portflow logo left-aligned without extra explanatory text next to it.
- Theme, font, and font-size switchers should be represented as controls in the UI so their later Settings wiring can follow the same design language.
- Sidebar tooltips are not required if the collapsed state already communicates the item clearly.
- In collapsed desktop sidebar mode, icon hit areas must remain comfortable and not look compressed.
- Collapsed sidebar KPI/status icons must stay centered and clipped inside their card/container.
- When collapsing the desktop sidebar, the content area must expand immediately via layout column resizing.

Tables and data density:
- Tables should stay compact and readable.
- Status should be compressed when possible, e.g. icon-only status with a tooltip.
- Use one clear table header per concept instead of repeating labels.
- Non-critical data can be shortened in the table and expanded in a details view.

Forms and headings:
- Every module heading should have a short explanatory line underneath.
- Form sections should be visually separated with whitespace and subtle panels.
- Primary actions should be placed consistently and be easy to scan.

Responsive rules:
- The design must be ready for mobile usage.
- Sidebar sections should collapse or stack on smaller screens.
- Button groups and tables should wrap or stack before they become unreadable.
- On mobile, tables should fall back to a more readable card layout where necessary.
- Long tables should scroll inside the table container instead of forcing the whole page to scroll first.

Reference implementation:
- Before changing the main app, update the mockup in `Mockup.html` to verify the intended visual language.
- The mockup is the current source of truth for the target style.
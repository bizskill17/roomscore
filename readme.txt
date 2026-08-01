= 3.16.28 =
* Added GitHub automatic update support for RoomScore.
* Added private repository authentication via ROOMSCORE_GITHUB_TOKEN.
* Configured updates to use GitHub Release ZIP assets for WordPress update packages.

= 3.14.41 =
- Removed the record-count text below Schedule Tasks In Progress and Schedule Tasks History.

= 3.14.40 =
- Moved the Schedule Tasks History Excel download button to the right side of the search bar.

= 3.14.35 =
- Removed the Status column from the Periodic Tasks register.

== Version 3.14.34 ==
- Removed the Dashboard user filter and filter button.
- Dashboard department-wise issue counts now apply automatically according to the logged-in employee.
- Global management users continue to see the overall department summary.

== Version 3.14.33 ==
- Added Schedule Tasks History under the Inspection menu.
- The history view shows every saved periodic-task room update, including update date, task, room, status, remarks, photo, user and next action date.
- Added search and Excel export for the complete update history.

== Version 3.14.31 ==
- Added Periodicity (Days) column to the Periodic Tasks table.

== Version 3.14.29 ==
- Added the Due Date column to the Periodic Tasks register.
- Due dates are displayed in dd/mm/yy format.

== Version 3.14.28 ==
- Removed the Task Schedule heading from Periodic Tasks.
- Moved the Excel download icon to the right side of the search toolbar.


== Version 3.14.22 ==
- Updated from the user-provided v3.14.21 plugin base.
- Periodic Tasks register now shows only the Task Schedule table.
- Removed the Due Date column from the Task Schedule table.

= 3.8.44 =
* Removed all View all links from Dashboard panels.
* Fixed Resolved Issues dashboard query so issues resolved today display correctly.

=== Room Score ===
Contributors: BizSkill
Stable tag: 3.16.01
Requires at least: 6.0
Requires PHP: 7.4

Frontend-first hotel room inspection application with a blank theme-independent template, automatic pages, role permissions, inspection templates, photo capture, issues, reports and responsive app navigation.

== Installation ==
1. Upload and activate the plugin.
2. Activation automatically creates database tables, application roles and frontend pages.
3. Open Room Score from the WordPress menu, then click Open Application.
4. Configure properties, rooms, templates and users from the frontend application.

== Frontend Shortcodes ==
[hrim_login]
[hrim_dashboard]
[hrim_properties]
[hrim_templates]
[hrim_inspection_form]
[hrim_history]
[hrim_issues]
[hrim_reports]
[hrim_users]
[hrim_profile]
[hrim_settings]

== Version 2.0.0 ==
* Added automatic frontend page creation without duplicates.
* Added plugin-owned blank page template.
* Added responsive sidebar and application shell.
* Added frontend property, template, user and settings management.
* Added inspection history, issue update modal, filters and CSV export.
* Added photo capture/upload during inspection.
* Added role-based frontend access and frontend login/profile screens.


= 2.3.0 =
* Split Properties, Floors and Rooms into separate frontend pages and navigation items.

= 2.6.0 =
* Fixed dummy-room generation when floor/property status values contain case or spacing differences.
* Added fallback detection so existing floors are not silently ignored.
* Added safe dummy-data cleanup.
* Added application repair utility for tables, roles and pages.
* Redesigned Settings into General, Application and Maintenance sections.
* Added automatic row counts, sorting and pagination to frontend tables.
* Preserved table-first screens with Add buttons at the top-right and modal Add/Edit forms.


== 2.7.0 ==
* Added Room Categories master.
* Room Category is now a required dropdown in Room form.
* Added many-to-many template assignment for room categories and individual rooms.
* Inspection form now shows the combined templates assigned through the room category and room-specific assignments.
* Added migration of legacy category text and single room template assignments.


== 3.0.0 ==
* Robust Room Category save verification and listing queries.
* Visible top-right Add Room Category button.
* New Audit Log master with searchable activity history.
* Added application repair and database upgrade support.
* Removed GROUP BY-dependent master queries for broader hosting compatibility.


== 3.1.0 ==
* Fixed Room Categories showing zero records even when duplicate validation confirmed an existing record.
* Replaced the correlated master-list query with a simple base query and relationship hydration for broader Hostinger/MariaDB compatibility.
* Added a visible database query error on the Room Categories page instead of silently showing an empty table.
* Normalized duplicate category validation using trimmed, case-insensitive matching.


= 3.8.85 =
* Split Section and Checkpoint into separate columns across issue-related tables.
* Renamed Document to Photo and Update Document to Update Photo in the application and exports.

= 3.2.0 =
* Repairs legacy rooms table by explicitly adding category_id and template_id columns.
* Adds missing indexes for room category and template relationships.
* Prevents Unknown column category_id errors when saving rooms on upgraded installations.


== 3.5.0 ==
* Added Checklist Sections master.
* Changed Add Checklist Point Section field from free text to active-section dropdown.
* Migrates existing section names safely and preserves inspection history.
* Groups inspection questions using the linked section master and display order.
* Protects sections from deletion while checklist points are linked.


== Version 3.6.0 ==
* Added reusable section-level checkpoint library.
* Create templates by selecting sections such as Toilet, Bathroom and Electrical.
* All active checkpoints in selected sections automatically appear in the template.
* Existing template checkpoints are migrated safely into reusable section checkpoints.
* Historical inspection answers continue to use their original checkpoint IDs.
* Deleting a template retains the reusable checkpoint library.

= 3.8.0 =
* Added room-level checkpoint exclusions.
* Added room-specific additional checkpoints.
* Excluded checkpoints are hidden only for the selected room.
* Additional checkpoints can be linked to an assigned template and section.
* Perform Inspection now calculates the effective checklist as template questions minus room exclusions plus room additions.
* N/A remains available during inspection for exceptional cases.

= 3.8.2 =
* Added manual issue creation from the frontend Issue Register.
* Inspection failures continue to create linked issues automatically.
* Added issue source, assignment, due date and extended status tracking.
* Added manual issue photographs and dependent property/floor/room validation.

== Changelog ==

= 3.16.05 =
* Keep the Edit Asset modal open after removing a checkpoint.
* Checkpoint removal now refreshes only the edit form content and retains the current asset context.

= 3.16.04 =
* Fixed asset checkpoint removal so a permanently removed standard checkpoint no longer appears in the selected asset's Applicable Checkpoints table.
* Removed checkpoints remain available in the master template and for other assets.

= 3.15.96 =
* Added AJAX-based save, update and delete handling so the complete application shell no longer refreshes after routine record changes.
* Retained a safe normal-submit fallback for hosting or security configurations that block AJAX requests.

= 3.14.25 =
* Modernized the Update Periodic Task button.
* Periodic Tasks action column now shows only Edit and Delete icons.

= 3.14.11 =
* Added Last Update Date, Last Update Remarks, and Next Action Date to room-wise Periodic Tasks.
* Room-task updates now store updated_at for reliable history display.


== 3.14.7 ==
* Added a dedicated Update Periodic Task page opened from the Edit action.
* Update form pre-fills task, applicability, periodicity, sections, start date, department, assignee, status, and active-room count.
* Updated tasks redirect back to the Periodic Tasks register.

== 3.14.6 ==
* Fixed Add Periodic Task form submission by posting to WordPress admin-post.php.
* Newly saved periodic tasks now redirect to and appear in the Periodic Tasks register.
* Fixed periodic task edit, delete, and room-completion forms to use the correct WordPress action endpoint.
= 3.14.5 =
* Reordered the Periodic Tasks submenu so Add Periodic Tasks appears before Periodic Tasks.
* Removed the duplicate Add Periodic Task button from the Periodic Tasks register view.


= 3.13.3 =
* Changed the Perform Room Inspection room selector loading text to "Search...".
* Changed the room placeholder to "Select Room".

= 3.12.4 =
* Removed the duplicate Close text button from the Add Occupancy room popup.
* Kept only the circular red close icon.
* Added a responsive mobile bottom-sheet layout for the room popup.
* Kept Room No. and Yes/No status controls usable on narrow mobile screens.

= 3.12.3 =
* Removed the Floor dropdown from Add Occupancy.
* Added an accordion list showing all floors one below another.
* Clicking a floor expands its rooms and inline Yes/No status buttons.
* Opening another floor closes the previously opened floor.

= 3.11.8 =
* Added Occupancy submenu with Add Occupancy, Update Office Count, and Occupancy Check.
* Added a dedicated Update Office Count view for today's blank System Count.
* Removed Add Occupancy action from the Occupancy Check list.

= 3.8.91 =
* Added Floor Manager field and column in Floors view.

= 3.8.89 =
* Combined Checkpoint and Issue into one full-width bordered panel in the Update Issue modal.
* Added a clear divider between Checkpoint and Issue while preserving the existing responsive layout.



= 3.8.88 =
* Moved floor and room above the Update Issue heading.
* Placed Status and Update Photo in one responsive row.
* Removed the Updated By field from the form.
* Expanded Checkpoint and Issue display blocks to use the full modal width.

= 3.8.86 =
* Renamed Update Issue Progress to Update Issue.
* Added separate Checkpoint and Issue details.
* Centered Floor / Room location above Status.
* Hid Update Date and now save current IST automatically.


= 3.8.85 =
* Added Floor, Room, and Section filters to Issues, Pending Assign Issue, Pending Issue, and Issue Resolved views.
* Filters apply automatically and Room options follow the selected Floor.
= 3.8.44 =
* Pending Assign Issue now shows only the Assign Issue action.
* Pending Issue now shows only the Update Progress action.

= 3.8.5 =
* Grouped inspection checkpoints by section and displayed each section heading only once.


== Version 3.8.6 ==
* Added Edit Issue action.
* Added distinct background colours to Assign, Edit, Update Progress and Delete action icons.


== 3.8.14 ==
* Added a blue eye action to Inspection History.
* Added a full inspection details modal grouped by checklist section.
* Displays answers, remarks, summary counts and generated issues.
* Added print support for inspection details.

Version 3.8.14
- Ensures all action and navigation icons render clearly in every frontend view.
- Replaces generic Edit/Delete Dashicons with reliable visible symbols.
- Adds explicit Dashicons font restoration and consistent colored action buttons.

= 3.8.15 =
* Fixed Add Room and Edit Room modal width.
* Removed horizontal left/right scrolling from wide forms.
* All room fields and template selections now fit within the modal.
* Retained vertical scrolling only when the form is taller than the screen.
* Added responsive single-column layout on mobile.


= 3.8.16 =
* Replaced the overlapping inspection-detail close control with one clear red circular X button.

= 3.8.19 =
* Removed visible Close text from inspection details; only the red circular X remains.
* Removed inspection status column and badges from inspection history, dashboard recent inspections and reports.
* Removed Failed Inspections KPI from dashboard.

Version 3.8.21
- Removed horizontal separator lines between checkpoint 1, 2, 3, etc. on Perform Inspection.

== 3.8.24 ==
* Automatic filters and consistent Inspector field height.

== 3.8.26 ==
* Changed all neutral grey table-cell and application borders to black.
* Applied black borders to tables, forms, cards, modals, filters, and plugin admin screens.
* Preserved intentionally coloured dashboard panel borders and action button colours.

== 3.8.27 ==
* Moved table search fields into the top filter panel for Inspection History, Issues, Pending Assign Issue, Pending Issue, and Issue Resolved.
* Kept Add Issue and Open Issue Register actions available without changing filtering or export behaviour.


= 3.8.32 =
* All frontend table headers now use the same accent colour as the selected sidebar view.
* Table header text, icons, links and sort indicators are white.
* Existing black cell borders remain unchanged.

= 3.8.30 =
* Added blue background to Clear filter buttons.
* Added green background to Download Excel buttons.
* Reinforced dark borders for all table cells.

= 3.8.33 =
* Forced solid black borders on all frontend tables and every table cell.

== 3.8.38 ==
* Replaced PDF text action with a clear white PDF document icon on green action buttons in Inspection History and Dashboard Recent Inspections.


== Version 3.8.41 ==
* Automatically hides Property fields and columns when only one active property exists.
* Auto-selects the single property in backend validation and save handlers.
* Property fields reappear automatically when multiple active properties exist.

= 3.8.42 =
* Renamed Delay Issues to Delayed Issues.
* Added Download Excel to all main application tables.
* Removed the room-selection instruction line from Perform Inspection.


== Version 3.8.45 ==
* Master list pages now show Search, Download Excel and Add action in one compact row.
* Removed duplicate in-card headings from Properties, Floors, Room Categories, Rooms, Checklist Sections, Inspection Templates and Users.

= 3.8.47 =
* Matched master search-field height with Add buttons.
* Applied green background and white text to every Download Excel button across all views.


= 3.8.48 =
* Added colour-coded selected inspection responses: Yes green, No red, N/A light blue.


= 3.8.49 =
* Added full checkpoint-area background colours based on the selected response.
* Yes uses a light green background, No uses a light red background, and N/A uses a light blue background.
* Existing mandatory remarks validation for No remains unchanged.


== Version 3.8.51 ==
* Fixed original issue documents to include linked inspection uploads.
* Fixed Document and Update Document columns and alignment.
* Fixed document modal opening and file display.

== Version 3.8.52 ==
* Fixed document Open buttons by defining the missing HTML attribute escaping helper in the main frontend script scope.
* Increased the asset version so browsers load the corrected JavaScript.


== Version 3.8.53 ==
* Removed document/image filenames from the document viewer.
* Removed separate View and Download buttons.
* Document thumbnails now open directly when clicked.


= 3.8.56 =
* Simplified Dashboard issue tables: Pending Assign hides Assigned To, Due Date and Status; Pending, Delayed and Resolved hide Status.
* Added a global loading spinner for form submissions, page navigation and AJAX requests.

= 3.8.55 =
* Added a Delayed Issues KPI card beside Open Issues.
* Made all four Dashboard KPI cards fully clickable.
* Total Rooms opens Rooms, Total Inspections opens Inspection History, Open Issues opens Issues, and Delayed Issues opens the delayed-only Pending Issue view.


= 3.8.60 =
* Removed Inspector, Date & Time, Total, and Passed / Failed summary cards from Inspection Details.


== Version 3.8.62 ==
* Pending Assign Issue now hides Assigned To, Due Date and Status columns.
* Kept Sl., Date, Floor, Room, Issue, Document and Assign action.

== Version 3.8.63 ==
* Kept the section-grouped Inspection Details view under the Inspection History eye action.
* Moved the logged-in user name and Logout link from the top header to the bottom of the left sidebar.

== Version 3.8.64 ==
* Added Floor, Room and Inspector filters to Inspection History.
* Room options are limited by the selected Floor.
* Filters apply automatically and Excel export respects the active query filters.

== Version 3.8.65 ==
* Added a read-only View action for Inspection In Progress records.
* View modal shows section-wise progress, saved responses, remarks and checkpoint photos.

== Version 3.8.67 ==
* Removed the separate View action from Inspection In Progress.
* Inspection rows now expand and collapse their section details when clicked.
* Continue and Delete actions remain independent.

= 3.8.67 =
* Inspection In Progress section details remain hidden until the inspection row is clicked.

== Version 3.8.68 ==
- Inspection In Progress now lists every section assigned to the room.
- Expanded section summary columns: Section, Total, Answered, Pending and Progress.
- Unanswered checkpoints remain visible inside expandable section details.

= 3.8.68 =
* Show all room sections and accurate draft progress totals.



= 3.8.73 =
* Inspection In Progress now lists every section assigned to the room, including completely unanswered sections.
* Total counts every available checkpoint; Answered counts only saved Yes/No responses; Pending and Progress are recalculated accurately.
* Main in-progress totals now use the same complete room checklist structure.

= 3.8.72 =
* Left-aligned Checkpoint and Remarks columns in inspection PDF.
* Strengthened black borders throughout printable inspection tables.
* Removed the Issues Generated table from downloaded inspection PDFs.

= 3.8.76 =
* Fixed Inspection In Progress row expansion so the nested section table is not affected by the main table pagination.
* All assigned room sections now remain visible in the expanded section summary, including sections with zero answers.

= 3.8.78 =
* Removed the Status column from the Pending Issue view while preserving the internal issue status workflow.

= 3.8.77 =
* Added a dedicated print-only section summary table to ensure complete black cell borders in downloaded PDFs.

= 3.8.79 =
* Separated Checkpoint and Issue into two distinct columns across Dashboard issue tables, Pending Assign Issue, Pending Issue, Delayed Issues, and Issue Resolved.
* Preserved section name below the checkpoint and kept issue remarks/description in the dedicated Issue column.
* Updated empty-row column spans and Excel exports to match the new table structure.


= 3.8.82 =
* Applied Montserrat SemiBold typography throughout the Room Score frontend, forms, controls, modals and plugin admin screens.

= 3.8.80 =
* Added live database row-count badges to data-based left-menu views.
* Counts respect inspection ownership for non-manager users and show 99+ above 99 records.



= 3.8.95 =
* Removed duplicate inner table titles from Inspection In Progress, Inspection History, Issue Management, Pending Assign Issue, Pending Issue, Issue Resolved, and Reports.
* Retained the main page heading, record count, filters, exports, actions, and table layout.

= 3.8.93 =
* Hidden the Audit Log view from the frontend navigation.


= 3.8.96 =
* Kept Yes, No, Photo and Remarks controls on one line on desktop, tablet and mobile.
* Mobile continues to show compact icon-only controls.



= 3.8.99 =
* Applied icon-only Yes, No, Photo and Remarks controls on desktop, tablet and mobile.
* Kept all four checkpoint actions on one line.
* Remarks stay hidden until the Remarks icon is clicked, while selecting No opens Remarks automatically.

= 3.8.98 =
* Mobile checkpoint controls now display as clean borderless icons only.
* Yes, No, Photo and Remarks remain on one line.

= 3.8.97 =
* Fixed mobile inspection action controls so Yes, No, Photo and Remarks always remain on one line.

= 3.9.00 =
* Expanded checkpoint remarks fields now use the full available width on desktop and tablet.
* Existing icon-only checkpoint controls and mobile responsiveness remain unchanged.

= 3.9.01 =
* Added compact divider lines between checkpoints.
* Added section-level Save buttons that save inspection progress without leaving the form.

= 3.9.02 =
* Reduced checkpoint top and bottom padding further.
* Removed unnecessary minimum height from checkpoint rows.
* Reduced the title-to-action icon gap.
* Kept remarks and photo previews full width when opened.


= 3.9.05 =
* Moved the desktop sidebar collapse/expand icon out of the sidebar and placed it immediately before each page title.
* Retained the remembered collapsed state and existing mobile hamburger navigation.

= 3.9.04 =
* Added a desktop-only Show / Hide Sidebar toggle at the bottom of the left menu.
* Removed the Source column from the Issues view while preserving the stored source data.

= 3.9.06 =
* Added a circular close icon at the top-right of the slide-out sidebar on mobile and tablet layouts.
* The close icon dismisses the menu without affecting the desktop sidebar toggle.

= 3.9.07 =
* Restyled the mobile and tablet sidebar close control to a clean TaskPro-style icon at the top-right of the sidebar header.
* Removed the circular border, filled background and shadow from the close control.
* Preserved the desktop title-side collapse control and existing mobile menu behaviour.

== 3.9.15 ==
* Removed pagination from Room Score and Floor Score.
* Updated Room Score column order and default room sorting.
* Updated Dashboard KPI cards for in-progress and pending work.
* Renamed Pending Issue to Pending Issues.
* Set frontend pagination to 20 rows per page.

= 3.9.17 =
* Removed My Profile from the left menu.
* Moved Users under Masters.
* Renamed Issue to Issues in Room Score and Floor Score.
* Replaced Download Excel text with an icon-only button.
* Removed dashboard KPI icons and aligned counts to the right.
* Sectionwise Checklist now shows numeric section counts only.


= Version 3.9.21 =
* Moved every Dashboard table Excel download icon to the right side of its table header.

= Version 3.9.22 =
* Fixed Dashboard table headers so the title and Excel download icon remain on the same line on desktop, tablet, and mobile.

= 3.12.5 =
* Simplified Update Office Count to a form-only view.
* Renamed occupancy summary counts to Security Count and Office Count.
* Removed duplicate inner headings from occupancy views.
* Updated historical Occupancy Check details to use the same floor-list and room-popup layout as Add Occupancy.

= 3.12.6 =
* Limited Office Count edits to five minutes from the latest Office Count update.
* Made Office Count read-only and removed the Update button after the edit window expires.
* Added backend enforcement for the five-minute Office Count lock.

= 3.12.8 =
* Added a Date, Office Count and Action table to Update Office Count.
* Added tick, edit and locked action states with a five-minute edit window.
* Opened Office Count entry in a modal.
* Sorted Update Office Count and Occupancy Check records with latest dates first.



= 3.13.2 =
* Kept all five Dashboard KPI cards in one row on desktop.
* Preserved responsive tablet and mobile wrapping.

= 3.13.1 =
* Floor progress count now refreshes immediately after occupancy room status auto-save.

= 3.13.0 =
* Added a lock icon immediately to the right of the No button when an occupancy room status is no longer editable.
* Historical Occupancy Check room statuses always display the lock icon.
* Preserved the five-minute edit window, auto-save behaviour and responsive popup layout.

= 3.12.9 =
* Add Occupancy floor rows now use the active theme colour with white floor progress text.

== Version 3.14.4 ==
* Fixed Periodic Task active-room count for room categories.
* Count now supports legacy room-category text mappings and case-insensitive active status values.
* The first available category is selected on the Add Periodic Task page so its room count loads immediately.

= 3.14.8 =
* Added a Task column to the room-wise Periodic Task view.
* Room update now edits only the selected periodic task, without loading inspection checkpoints.
* Added Pending/Completed status, remarks, photo evidence and automatic completed date.
* Task Schedule progress recalculates from room-task completion status.

= 3.14.13 =
* Added Periodic Tasks > Update Log view.
* Creates one permanent log entry for every room-level periodic task update.
* Shows update date, task, room, status, remarks, photo, updated user and next action date.

= 3.14.14 =
* Periodic Tasks register now shows only the Task Schedule table.
* Removed the Due Date column from Task Schedule.
* Added Task Schedule search and empty-state row.

= 3.14.26 =
* Kept Open Task, room task updates, validation returns and save redirects inside Schedule Tasks In Progress instead of opening the Periodic Tasks view.

= 3.14.32 =
* Moved Task immediately after Sl. in Periodic Tasks.
* Periodic Tasks now shows only the latest cycle for each task and rolls the Due Date forward when a new cycle is generated.
* A new due cycle is automatically created in Schedule Tasks In Progress when its date arrives, with applicable room rows prepared automatically.

= 3.14.36 =
* Added modern background colours to Periodic Tasks edit and delete action buttons.


= 3.14.37 =
* Add Periodic Task button now changes to “Submitting...” and becomes disabled after form submission to prevent duplicate saves.

= 3.14.38 =
* Schedule Tasks In Progress status is now calculated from progress: 0% shows Yet to Start, 1–99% shows In Progress, and 100% shows Completed.

= 3.14.39 =
* Removed Completed and Progress columns from the Periodic Tasks master view.


= 3.15.74 =
* Added expandable Meta / WhatsApp template setup instructions with official links, exact template formats, variables and sample values.

= 3.15.94 =
* Removed the Asset Categories master and navigation entry.
* Removed Asset Category from Add Asset and Edit Asset.
* All active inspection templates are now shown directly in the Asset template dropdown.
* Updated user-facing Room/Rooms terminology to Asset/Assets while retaining the Room Score brand.

= 3.15.95 =
* Preserved user-entered Room/Rooms values inside Asset Type, Asset records, table data and dropdown options.
* Asset terminology replacement now applies only to interface labels and does not rename stored master data.


= 3.16.03 =
* Added a remove cross action against every applicable checkpoint in the Asset edit form.
* Removing a standard checkpoint excludes it only from the selected asset and does not alter the master template or other assets.


= 3.16.06 =
* Improved overall mobile responsiveness across forms, modals, toolbars, filters, periodic-task forms, settings forms, issue forms, and login screens.
* Form fields, action buttons, multiselect controls, and modal layouts now stack and expand properly on mobile.
* Mobile table wrappers and checkpoint/configuration tables now scroll cleanly without breaking the form layout.


= 3.16.07 =
* Reduced mobile toolbar button sizes across the app.
* Master-list action bars now stack more cleanly on mobile, with search full width and action buttons arranged in compact rows.
* Download, upload, export, add, and similar buttons are now smaller and fit narrow mobile screens better.


= 3.16.08 =
* Hidden Download Template and Upload Template controls on mobile master views.
* Other master toolbar actions now display as compact icon-only buttons on mobile.
* Search remains visible and uses the remaining available mobile width.


= 3.16.09 =
* Corrected the Applicable Checkpoints table for narrow mobile screens.
* Checkpoint, Show and Action columns now remain visible together without horizontal clipping.
* Checkpoint text wraps cleanly and section Add/Delete controls use compact mobile icons.


= 3.16.10 =
* Reduced the mobile width of the Show and Action columns in Applicable Checkpoints.
* All three columns now fit within narrow mobile screens without horizontal scrolling.
* Mobile headers now use an eye icon for Show and a cross icon for Action.


= 3.16.11 =
* Standardized delete action buttons to match edit buttons in size, shape, alignment, spacing, and theme styling across all list views.
* Corrected mobile form styling that was compressing delete buttons inside action cells.


= 3.16.12 =
* Tightened the mobile Applicable Checkpoints table so the Checkpoint, Show, and Action columns fit on one screen without horizontal scrolling.
* Reduced checkbox, delete, plus-button, and header icon sizes on narrow screens.
* Reduced padding and font sizes in the mobile Applicable Checkpoints section for better fit on iPhone-sized devices.


= 3.16.13 =
* Updated the mobile Applicable Checkpoints table so long checkpoint text wraps within the checkpoint column.
* Show and Action columns now stay visible on the same mobile screen without requiring a horizontal scrollbar.
* Reduced mobile icon, checkbox, and action-button widths further for narrow screens.


= 3.16.14 =
* Increased mobile text size in the Applicable Checkpoints table for better readability.
* Increased mobile checkbox, plus-button, and delete-action sizes while keeping all three columns visible on one screen.


= 3.16.15 =
* Adjusted the Applicable Checkpoints section-heading layout so the add (+) icon stays aligned on the right side, including on mobile screens.
* Preserved left alignment for the section title while keeping the add icon as a separate right-side action.


= 3.16.16 =
* Fixed the mobile Applicable Checkpoints section-heading layout so section names no longer render vertically.
* Kept checkpoint text wrapped normally while Show and Action remain visible on the same screen.
* Kept each section add (+) icon aligned at the far right without horizontal scrolling.


= 3.16.17 =
* Increased the Show and Action column widths in the mobile Applicable Checkpoints table.
* Increased the mobile checkpoint text size and related control sizes for better readability.


= 3.16.18 =
* Fixed the Show and Action columns on the right side of the mobile Applicable Checkpoints table.
* The checkpoint column now automatically uses the remaining screen width and wraps text according to the available space.
* Removed horizontal overflow from the Applicable Checkpoints table on narrow screens.


= 3.16.19 =
* Unchecking Show and saving now keeps the checkpoint visible in the Applicable Checkpoints table as an unchecked row.
* Unchecked checkpoints remain excluded from inspections for that asset.
* Only the Action cross permanently removes a checkpoint from the asset table.


= 3.16.20 =
* The checkpoint cross action now permanently deletes the checkpoint assignment from the selected asset's backend data.
* Unchecking Show continues to hide the checkpoint only and keeps it available in the Applicable Checkpoints table.
* Permanent deletion remains limited to the selected asset; the master template checkpoint and other assets remain unaffected.


= 3.16.21 =
* Fixed Show and Action columns at the right side of the mobile Applicable Checkpoints table.
* The Checkpoint column now automatically occupies the remaining width and wraps long text without horizontal scrolling or clipping.


= 3.16.22 =
* Rebuilt the mobile Applicable Checkpoints table as a fixed three-column grid.
* Checkpoint text now wraps in the flexible first column, while Show and Action remain permanently visible on the right without horizontal scrolling.


= 3.16.23 =
* Hard-fixed the mobile Applicable Checkpoints table so Checkpoint, Show, and Action always remain visible together.
* Added a runtime mobile layout correction for dynamically rendered edit modals.
* Moved the checkpoint-management hidden field outside the table body to keep the table markup valid.


= 3.16.24 =
* Added mobile card view for Room Score and Floor Score lists.
* Room Score and Floor Score tables now display as stacked cards on mobile, improving readability without horizontal scrolling.


= 3.16.25 =
* Changed Room Score and Floor Score mobile cards to show all details inside one compact card.
* Removed the serial-number field from mobile score cards.
* Arranged score-card details in a compact two-column mobile layout.


= 3.16.26 =
* Updated Room Score and Floor Score mobile cards to show one record per card with left-side headings and right-side values.
* Each detail now appears as a single row inside the card, improving mobile readability.


= 3.16.27 =
* Refined Room Score and Floor Score mobile cards with a cleaner corporate style.
* Removed inner borders inside mobile score cards for a smoother and more premium look.

# Changelog

## 0.3.1 - 2026-07-29

- Fixed Trip edit updates being blocked by required fields inside hidden inline Add Group and Add Organization panels.

## 0.3.0 - 2026-07-29

- Moved Organizations access out of the Operations Dashboard submenu and into the parent Configuration page.
- Added a Trip Billing page for completed trips with manual invoice confirmation, mileage-required blocking, invoice PDF emailing, and stored invoice PDF copies on trip records.
- Added Group billing address fields for Other-organization billing, automatic creation of an Other organization, and Trips settings for invoice/confirmation/cancellation email templates plus billable hour and mileage rules.
- Required emails on Groups and Organizations, while keeping the Other organization exempt, and blocked deletion of Groups, Organizations, and Schools that are tied to past trips.
- Locked each Group to its first assigned Organization so the relationship can not be changed later, including by administrators.

## 0.2.27 - 2026-07-29

- Fixed Groups and Organizations list Back to Trips buttons so they always return to the Trips list instead of following the latest admin referrer.

## 0.2.26 - 2026-07-29

- Fixed the Organizations admin page by shortening the internal Organization post type key to stay within WordPress post type limits.

## 0.2.25 - 2026-07-29

- Added Trips Organizations for non-school billing entities, quick Organization creation on Trip edit screens, and Manage Organizations access from the Trips list.
- Renamed visible Trip Details terminology from School/School Group/Advisor to Organization/Group/Primary Contact and noted that organizations receive group activity bills.
- Hid the inline Add Group form when a group is selected from the dropdown, removed the generated trip title "Trip" prefix, and cancel school-based trips with driver notifications when the selected school is closed for the trip date.

## 0.2.24 - 2026-07-29

- Updated Trips Smart Monitor cards so the first line shows the pickup school and trip location in the format "MVU | Smuggler's Notch".

## 0.2.23 - 2026-07-27

- Added an Add Any Driver toggle to trip assignment driver selectors so trip coordinators can reveal drivers outside normal trip-date availability while preserving route-conflict confirmation.

## 0.2.22 - 2026-07-27

- Preserved customized Trip Coordinator role permissions from the parent Roles settings screen instead of removing Operations Dashboard and Manage Operations on every load.

## 0.2.21 - 2026-07-27

- Replaced Google Maps setup instructions in Settings > Integrations with a direct Google Cloud console link.

## 0.2.20 - 2026-07-27

- Adopted the parent dynamic admin back button helper for trip and school trip group pages.

## 0.2.19 - 2026-07-27

- Removed broad Operations Dashboard and Manage Operations defaults from the Trip Coordinator role so trips access is controlled independently.

## 0.2.18 - 2026-07-25

- Limited Trip driver assignment dropdowns to drivers with Evening availability or extra-run availability for the selected trip dates.

## 0.2.17 - 2026-07-25

- Linked driver trip assignment notifications to the matching My Dashboard trip row with automatic section opening, scrolling, and pulse highlighting.

## 0.2.16 - 2026-07-25

- Highlighted Trips Smart Monitor cards with vacant assignments using the same red vacant styling as Dispatch monitor route vacancies.

## 0.2.15 - 2026-07-25

- Matched Trips Smart Monitor day counts and empty-state messages to the larger school/group and assignment text size.

## 0.2.14 - 2026-07-25

- Refined Trips Smart Monitor typography by restoring the trip destination size while enlarging day counts, empty messages, school/group details, and assignment rows.

## 0.2.13 - 2026-07-25

- Enlarged Trips Smart Monitor typography for better readability on large TV displays across a room.

## 0.2.12 - 2026-07-25

- Changed the Trips Smart Monitor to use forward rolling Sunday-Saturday columns while always showing weekend columns.

## 0.2.11 - 2026-07-25

- Injected a Trips Smart Monitor dashboard, page shortcode registration, Sunday-Saturday trip monitor data, and a monitor renderer that matches the dispatch board layout.

## 0.2.10 - 2026-07-25

- Added driver notifications when a driver is removed or swapped off a trip assignment.

## 0.2.9 - 2026-07-25

- Connected Trips by School reporting to the core report availability checks so trip school and group filters only show options with trips in the selected date range and PDF creation is disabled when no trips match.

## 0.2.8 - 2026-07-25

- Changed Trips by School PDF assignment details to render as an indented nested table directly under each trip row with bus, driver, pre-trip mileage, post-trip mileage, and total mileage columns.
- Refined the Trips by School PDF layout with wider pickup dates, shorter uppercase month labels, narrower destination cells, and group-level total mileage summaries.

## 0.2.7 - 2026-07-25

- Simplified the Trips by School PDF columns to Pickup, School, Advisor, Destination, and Total Actual Mileage.
- Added one bus assignment mileage detail row under each trip using pre-trip and post-trip actual mileage.

## 0.2.6 - 2026-07-25

- Added a Trips by School PDF report type to core Reporting.
- Added School and School Group report filters, with group options limited to groups that had trips in the selected date range and selected school.
- Grouped the generated PDF by school group rather than by school.

## 0.2.5 - 2026-07-25

- Moved driver trip assignments into the core My Driver Dashboard Assignments section so they render once in the requested order.
- Changed trip assignment and reminder notifications to open My Driver Dashboard instead of opening Maps directly.
- Limited driver dashboard trip assignments to current and future trips, with the Map column still providing the Open button for directions.

## 0.2.4 - 2026-07-25

- Recognized existing trip-created route vacancies as confirmed route conflicts so legacy confirmations do not reopen the popup.

## 0.2.3 - 2026-07-25

- Persisted confirmed driver route conflicts so unchanged conflicts do not prompt again.
- Added per-driver assignment status text, including Vacant for unassigned driver slots.

## 0.2.2 - 2026-07-25

- Grouped Actuals time and mileage fields into stacked pairs that flow inline and wrap on smaller screens.

## 0.2.1 - 2026-07-25

- Moved trip route conflict status beside each driver selector.
- Prevented duplicate bus selections in the Trip editor and disabled buses booked on overlapping trips.

## 0.2.0 - 2026-07-25

- Added Route Coverage-backed driver conflict checks, confirmation gating, and trip-created route vacancy handoff.
- Added driver-grouped route conflict popups and green route conflict status messaging on Trip edit screens.

## 0.1.36 - 2026-07-25

- Added published-trip Actuals rows under each bus assignment for yard, departure, arrival, return, post-trip times, and mileage capture.

## 0.1.35 - 2026-07-25

- Linked the Trips list Advisor column to the selected school group record.
- Changed the trip estimate info icon from hover text to a click-open popover.

## 0.1.34 - 2026-07-25

- Expanded the Trips admin list columns to include advisor, return time, assignment slot details, and last modified user attribution.
- Relabeled Arrival and Return trip times as Estimated Time and refreshed them when Google estimates, Pickup, Departure, or estimated travel time changes.

## 0.1.33 - 2026-07-25

- Rounded Google-buffered one-way travel time estimates up to the next 10-minute interval.
- Added a travel time tooltip explaining buffer, rounding, and manual override behavior.
- Refreshed automatic Arrival and Return estimates when school or destination details change while preserving manual overrides otherwise.

## 0.1.32 - 2026-07-25

- Replaced native Trip time pickers with friendly typed time inputs that normalize to saved 24-hour times.

## 0.1.31 - 2026-07-25

- Fixed an infinite schedule-change loop when selecting Trip pickup dates.

## 0.1.30 - 2026-07-25

- Moved the Trip Google Maps Diagnostics settings section onto the core Settings > Tools extension hook.

## 0.1.29 - 2026-07-25

- Added a Settings > Tools toggle to enable or disable Trip Google Maps route diagnostics.
- Hid Google Route Options diagnostics from Trip edit screens unless the Tools diagnostics toggle is enabled.

## 0.1.28 - 2026-07-25

- Added the exact estimate origin, destination, and matching Google Maps directions link to the Google Route Options diagnostic panel.

## 0.1.27 - 2026-07-25

- Removed the manual Google estimate refresh control and visible status message from the Trip editor.
- Added a Google Route Options diagnostic panel that shows returned route alternatives and marks the selected estimate route.

## 0.1.26 - 2026-07-25

- Set Google route estimate departure times slightly in the future to satisfy Routes API traffic-aware timestamp validation.

## 0.1.25 - 2026-07-25

- Added visible estimate refresh status and Google Routes API error messages to the Trip editor.
- Added a manual Refresh Google Estimate action that uses the same forced update path as automatic estimate refreshes.
- Returned route estimate diagnostics from the server so failed updates no longer leave stale values silently.

## 0.1.24 - 2026-07-25

- Forced auto-estimate refreshes to overwrite mileage and travel time with rounded values on address, location, and school changes.
- Switched trip route estimates to Google's optimistic traffic model when choosing the fastest route.

## 0.1.23 - 2026-07-25

- Restored debounced auto-refresh of mileage and travel-time estimates while destination addresses are edited manually.

## 0.1.22 - 2026-07-25

- Switched route estimates to traffic-aware fastest-route duration so time matches Google Maps "Leave now" behavior more closely.
- Normalized estimated round-trip mileage to whole miles in the editor and on save.
- Refreshed existing trip estimates when opening an edit screen with a saved school and destination.

## 0.1.21 - 2026-07-25

- Refreshed Google mileage and travel-time estimates whenever the destination changes, even after manual estimate edits.
- Switched Google travel-time estimates to the fastest static route duration before applying the configured time buffer.

## 0.1.20 - 2026-07-25

- Rounded Google estimated round-trip mileage up to a whole mile while keeping the configured buffer limited to travel-time estimates.

## 0.1.19 - 2026-07-25

- Added Location Name lookup with selectable place suggestions that fill destination address.
- Biased location-name lookup toward the selected school's stored address.
- Added Geocoding API fallback for destination lookup when Places Text Search is blocked.
- Updated Google integration instructions to include Geocoding API.

## 0.1.18 - 2026-07-25

- Removed the manual draft suffix from Trip titles so WordPress owns draft labeling.
- Switched destination lookup suggestions from Places Autocomplete to Places Text Search.

## 0.1.17 - 2026-07-25

- Fixed Trip Save Draft being blocked by hidden inline School Group required fields.

## 0.1.16 - 2026-07-25

- Updated draft trip titles to use available trip fields.

## 0.1.15 - 2026-07-25

- Redirected Trip Save Draft actions back to the Trips list with a saved notice.
- Added fallback draft titles in the format "Trip - draft saved date and time" until the full trip naming fields are complete.
- Included incomplete trip drafts without pickup dates in the default Today+ Trips list and count.

## 0.1.14 - 2026-07-25

- Reworked Trip editing into a staged workflow: Trip Details, Destination & Estimates, Dates & Times, then Buses & Drivers.
- Added Google Places Text Search fallback and visible Google API errors for destination lookup.
- Auto-calculated Arrival and Return times from Google travel time estimates and the configured buffer.
- Hid the manual Trip title field and generated trip titles from school group, school nickname, destination, and pickup date.
- Prevented incomplete staged trips from being published while still allowing Save Draft.

## 0.1.13 - 2026-07-25

- Added server-side Google Places destination address lookup on the Trip edit screen.
- Defaulted Arrival, Departure, and Return dates from the selected Pickup date unless manually overridden.
- Made Buses & Drivers bus slots update live from the Buses Needed value.
- Updated Google integration instructions to include Places API (New).

## 0.1.12 - 2026-07-24

- Improved the Trip Details layout so school/group selection, inline group creation, and schedule fields are visually grouped and responsive.
- Required inline School Trip Group creation to include group name and advisor first and last names.

## 0.1.11 - 2026-07-24

- Filtered Trip school groups to the selected school and added advisor names to group dropdown labels.
- Added inline School Trip Group creation on the Trip edit screen without leaving or refreshing the trip form.
- Locked the Destination & Estimates and Buses & Drivers panels until both School and School Group are selected.

## 0.1.10 - 2026-07-24

- Moved the School Trip Groups Back to Trips button directly under the page title.
- Changed the School Trip Group edit screen back button to return to School Trip Groups and removed the advisor contact action buttons from that edit screen.

## 0.1.9 - 2026-07-24

- Added a Back to Trips button to the School Trip Groups list page header.

## 0.1.8 - 2026-07-24

- Removed the duplicate Trips module landing page from the Terricel Transit menu.
- Added a Manage School Groups button beside Add New Trip on the Trips list page.
- Added a school trip group advisor main phone extension field.

## 0.1.7 - 2026-07-24

- Updated school trip group advisor phone fields to use the core Terricel phone formatter.
- Added core phone input masking support to advisor phone fields.

## 0.1.6 - 2026-07-24

- Improved the School Trip Group edit screen with a structured group details panel.
- Added group advisor first name, last name, main phone, emergency phone, and email fields.
- Added clickable phone and email contact links for saved school trip group advisor contacts.

## 0.1.5 - 2026-07-24

- Added a default Today+ view for the Trips admin list so upcoming trips are shown by default.
- Added an All view for Trips so past trips remain accessible.

## 0.1.4 - 2026-07-24

- Removed the editable Google API restricted IP field from Integrations settings.
- Kept the Google IP warning automatic so it only appears when the saved site outbound IP no longer matches the currently detected outbound IP.

## 0.1.3 - 2026-07-24

- Moved the detected site outbound IP into the collapsible Google API setup instructions.
- Kept the Google API restricted IP comparison dynamic so instructions update when the server outbound IP changes.

## 0.1.2 - 2026-07-24

- Added Google API IP restriction verification to the Integrations settings tab.
- Added detected site outbound IP guidance for locking the Routes API key to the WordPress server.

## 0.1.1 - 2026-07-24

- Added Google Maps API setup instructions to the Integrations settings tab.
- Switched trip mileage and travel-time estimates to Google Routes API.

## 0.1.0 - 2026-07-24

- Added initial Terricel Transit Trips child plugin scaffold.
- Added trip coordinator role and trip management capability integration.
- Added trip and school group record types.
- Added bus trip eligibility, trip assignment, conflict confirmation, and notification scaffolding.

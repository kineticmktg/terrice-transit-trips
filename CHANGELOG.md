# Changelog

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

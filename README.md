# splynx_ticket_map
Displays a map of Splynx tickets with tools to help organise and dispatch agents

Requires https://github.com/scracha/splynx-fast-lookup to be implemented first in order to pull in relevant customer service information.

ticket_exporter_cli.php   -  Pulls in support tickets from splynx.  Utilise the data store from splynx-fast-lookup to match service information and obtain latitude and longitude.
ticket_map.php   - Plots every open ticket via Google Maps with icons based on the ticket type.  Sidebar provides sorting and filtering options.  Filtering options are saved to localStorage so they'll work after refreshing.  Dispatch tools include being able to print a list of selected tickets or link to google maps for navigation.
config.php  - API keys, splynx credentials, google maps API keys, company titles etc.
SplynxApiClient.php  -  Wrapper for handling the Splynx API
ticket_api.php  - Endpoint the serves the JSON data created by ticket_exporter_cli.php to the mapping front end
googleMapsApi.php  -  Renders map markers and information windows.



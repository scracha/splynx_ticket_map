<?php
/**
 * Splynx Ticket Map Dashboard
 * Version: 4.7.0 - Filter Persistence (localStorage)
 */

require_once 'config.php';
require_once 'googleMapsApi.php';
global $googleApiKey, $dashboardTitle; 

const TICKET_STORE_PATH = '/dev/shm/splynx_open_tickets.json';
$tickets = file_exists(TICKET_STORE_PATH) ? json_decode(file_get_contents(TICKET_STORE_PATH), true) : [];

$lastSyncTime = file_exists(TICKET_STORE_PATH) ? filemtime(TICKET_STORE_PATH) : null;
$syncDisplay = $lastSyncTime ? date("g:i A", $lastSyncTime) : "Never";

$priorityMap = ['urgent', 'high', 'normal', 'low'];
$priorityOrder = ['urgent' => 1, 'high' => 2, 'normal' => 3, 'low' => 4];

usort($tickets, function($a, $b) use ($priorityOrder) {
    $pA = $priorityOrder[strtolower($a['priority'] ?? 'normal')] ?? 5;
    $pB = $priorityOrder[strtolower($b['priority'] ?? 'normal')] ?? 5;
    return ($pA !== $pB) ? $pA <=> $pB : $b['ticket_id'] <=> $a['ticket_id'];
});

$agents = array_unique(array_column($tickets, 'assigned_to')); sort($agents);
$statuses = array_unique(array_column($tickets, 'status_label')); sort($statuses);
$types = array_unique(array_column($tickets, 'type_label')); sort($types);
$rawPriorities = array_unique(array_column($tickets, 'priority'));
usort($rawPriorities, function($a, $b) use ($priorityMap) {
    $posA = array_search(strtolower($a), $priorityMap);
    $posB = array_search(strtolower($b), $priorityMap);
    return ($posA === false ? 99 : $posA) <=> ($posB === false ? 99 : $posB);
});

$typeConfig = [
    'Order service request' => ['icon' => 'flight',          'color' => '#8b5cf6'],
    'Service change'        => ['icon' => 'local_atm',       'color' => '#10b981'],
    'Problem'               => ['icon' => 'local_taxi',      'color' => '#f59e0b'],
    'FAULT'                 => ['icon' => 'report',          'color' => '#ef4444'],
    'Accounts'              => ['icon' => 'account_balance', 'color' => '#0ea5e9'],
    'Feature Request'       => ['icon' => 'rate_review',     'color' => '#3b82f6'],
    'Installation'          => ['icon' => 'home',            'color' => '#ec4899'],
    'Question'              => ['icon' => 'emoji_people',    'color' => '#f97316'],
    'Incident'              => ['icon' => 'agriculture',     'color' => '#f43f5e'],
    'default'               => ['icon' => 'push_pin',        'color' => '#64748b']
];

$mapTickets = array_filter($tickets, function($t) {
    return isset($t['lat']) && (float)$t['lat'] !== 0.0;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($dashboardTitle); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        #map { height: 100%; width: 100%; }
        .sidebar-scroll { flex: 1; overflow-y: auto; scroll-behavior: smooth; }
        .multi-select-box { max-height: 100px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 0.5rem; background: white; padding: 0.4rem; }
        .ticket-card.selected { background-color: #eff6ff; border-left-width: 8px !important; }
        .ticket-card.flash-highlight { background-color: #fef08a !important; }
        .filter-group.minimized .multi-select-box, .filter-group.minimized .toggle-btn { display: none; }
        @media print { .no-print { display: none !important; } }
        .map-search-container { position: absolute; top: 10px; left: 50%; transform: translateX(-50%); z-index: 5; width: 350px; }
    </style>
</head>
<body class="h-screen flex flex-col overflow-hidden">

    <header class="bg-slate-900 text-white p-4 shadow-lg flex justify-between items-center z-10 no-print">
        <div class="flex items-center space-x-3">
            <div class="p-2 bg-blue-600 rounded-lg font-bold">WB</div>
            <div>
                <h1 class="text-lg font-bold"><?php echo htmlspecialchars($dashboardTitle); ?></h1>
                <div class="text-[10px] text-slate-400 font-bold">REFRESHED: <span class="text-emerald-400 uppercase"><?php echo $syncDisplay; ?></span></div>
            </div>
        </div>
        
        <div class="hidden md:flex items-center bg-slate-800 px-4 py-2 rounded-full border border-slate-700 space-x-4">
            <div class="flex items-center">
                <span class="material-icons text-emerald-400 text-sm mr-2">location_on</span>
                <span class="text-xs font-bold tracking-widest uppercase">Visible Markers: <span id="headerMarkerCount" class="text-emerald-400 ml-1">0</span></span>
            </div>
            <div class="h-4 w-px bg-slate-600"></div>
            <div class="flex items-center">
                <span class="material-icons text-red-400 text-sm mr-2">location_off</span>
                <span class="text-xs font-bold tracking-widest uppercase mr-2">No Address: <span id="headerNoAddressCount" class="text-red-400 ml-1">0</span></span>
                <input type="checkbox" id="noAddressOnlyToggle" class="w-4 h-4 cursor-pointer accent-red-500" onchange="applyFilters()">
            </div>
        </div>

        <div class="flex items-center space-x-2">
            <button onclick="getDirections()" class="bg-blue-600 px-3 py-2 rounded text-xs font-bold shadow-md">NAVIGATE</button>
            <button onclick="printSelected()" class="bg-emerald-600 px-3 py-2 rounded text-xs font-bold shadow-md">PRINT (<span id="selectedCount">0</span>)</button>
        </div>
    </header>

    <div class="flex flex-1 overflow-hidden">
        <aside class="w-96 bg-white shadow-2xl z-10 flex flex-col no-print">
            <div class="p-4 bg-slate-50 border-b">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="text-[10px] font-black text-slate-400 uppercase">Filters</h2>
                    <button onclick="toggleAllFilters()" id="globalToggle" class="text-[10px] bg-slate-200 px-2 py-1 rounded font-bold">MINIMISE FILTERS</button>
                </div>

                <div id="filterContainer" class="space-y-3">
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Personnel</span><button onclick="toggleGroup('agent-checkbox')" class="toggle-btn text-[9px] text-blue-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box">
                            <?php foreach ($agents as $a): ?><label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($a); ?>" checked class="mr-2 agent-checkbox filter-cb"><?php echo htmlspecialchars($a); ?></label><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Priority</span><button onclick="toggleGroup('priority-checkbox')" class="toggle-btn text-[9px] text-blue-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box">
                            <?php foreach ($rawPriorities as $p): 
                                $c = (strtolower($p) == 'urgent') ? 'text-red-600 font-bold' : ((strtolower($p) == 'high') ? 'text-orange-500 font-bold' : 'text-slate-600');
                            ?><label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($p); ?>" checked class="mr-2 priority-checkbox filter-cb"><span class="<?php echo $c; ?>"><?php echo ucfirst(htmlspecialchars($p)); ?></span></label><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Ticket Type</span><button onclick="toggleGroup('type-checkbox')" class="toggle-btn text-[9px] text-blue-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box">
                            <?php foreach ($types as $tType): 
                                $cfg = $typeConfig[$tType] ?? $typeConfig['default'];
                            ?>
                            <label class="flex items-center text-[11px] p-0.5 cursor-pointer hover:bg-slate-50">
                                <input type="checkbox" value="<?php echo htmlspecialchars($tType); ?>" checked class="mr-2 type-checkbox filter-cb">
                                <span class="material-icons text-[14px] mr-1" style="color:<?php echo $cfg['color']; ?>"><?php echo $cfg['icon']; ?></span>
                                <?php echo htmlspecialchars($tType); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="filter-group">
                        <div class="flex justify-between mb-1"><span class="text-[10px] font-bold text-slate-500 uppercase">Status</span><button onclick="toggleGroup('status-checkbox')" class="toggle-btn text-[9px] text-blue-600 font-bold">Toggle All</button></div>
                        <div class="multi-select-box">
                            <?php foreach ($statuses as $s): ?><label class="flex items-center text-[11px] p-0.5"><input type="checkbox" value="<?php echo htmlspecialchars($s); ?>" checked class="mr-2 status-checkbox filter-cb"><?php echo htmlspecialchars($s); ?></label><?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <input type="text" id="tSearch" placeholder="Search customer or subject..." class="w-full mt-3 p-2 border rounded text-xs outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div class="sidebar-scroll" id="ticketList">
                <?php foreach ($tickets as $t): 
                    $baseUrl = rtrim($t['ui_url'] ?? '', '/');
                    $p = strtolower($t['priority'] ?? 'normal');
                    $pColor = ($p === 'urgent') ? 'border-red-600' : (($p === 'high') ? 'border-orange-500' : 'border-slate-300');
                    $hasAddress = (isset($t['lat']) && (float)$t['lat'] !== 0.0);
                    $createdDate = isset($t['created_at']) ? date("d M, g:i A", strtotime($t['created_at'])) : 'N/A';
                ?>
                <div class="ticket-card p-4 border-b hover:bg-slate-50 cursor-pointer border-l-4 transition-all <?php echo $pColor; ?>"
                     id="card-<?php echo $t['ticket_id']; ?>"
                     data-agent="<?php echo htmlspecialchars($t['assigned_to']); ?>"
                     data-status="<?php echo htmlspecialchars($t['status_label']); ?>"
                     data-type="<?php echo htmlspecialchars($t['type_label']); ?>"
                     data-priority="<?php echo htmlspecialchars($t['priority']); ?>"
                     data-has-address="<?php echo $hasAddress ? '1' : '0'; ?>"
                     onclick="handleSidebarCardClick('<?php echo $t['ticket_id']; ?>', event)">
                    
                    <div class="flex justify-between items-start mb-1">
                        <div class="flex items-center gap-2">
                            <a href="<?php echo $baseUrl; ?>/admin/tickets/opened--view?id=<?php echo $t['ticket_id']; ?>" 
                               target="_blank" onclick="event.stopPropagation();" 
                               class="text-[10px] font-bold text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded uppercase">#<?php echo $t['ticket_id']; ?></a>
                            <span class="text-[9px] font-black uppercase tracking-tighter opacity-70"><?php echo $p; ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[9px] text-slate-400 font-bold uppercase italic"><?php echo $createdDate; ?></span>
                            <input type="checkbox" id="check-<?php echo $t['ticket_id']; ?>" class="w-4 h-4 cursor-pointer" onclick="event.stopPropagation(); toggleHighlight('<?php echo $t['ticket_id']; ?>')">
                        </div>
                    </div>

                    <div class="font-bold text-sm text-slate-800 leading-snug truncate mb-0.5"><?php echo htmlspecialchars($t['subject']); ?></div>
                    <a href="<?php echo $baseUrl; ?>/admin/customers/view?id=<?php echo $t['customer_id']; ?>" 
                       target="_blank" onclick="event.stopPropagation();"
                       class="text-sm font-black text-blue-700 hover:underline block truncate mb-1">
                        <?php echo htmlspecialchars($t['customer_name']); ?>
                    </a>

                    <div class="space-y-2 mt-2">
						<div class="flex items-center text-slate-600 text-[11px]">
							<span class="material-icons text-[14px] mr-1.5 text-slate-400">person</span>
							<span class="font-bold"><?php echo htmlspecialchars($t['assigned_to'] ?: 'Unassigned'); ?></span>
						</div>
						<div class="flex items-start text-slate-600">
							<span class="material-icons text-[16px] mr-1.5 text-blue-500 mt-0.5">location_on</span>
							<span class="text-sm leading-tight font-medium"><?php echo htmlspecialchars($t['service_address']); ?></span>
						</div>
						<div class="flex items-center text-slate-900">
							<span class="material-icons text-[16px] mr-1.5 text-emerald-600">phone</span>
							<?php if (!empty($t['customer_phone']) && $t['customer_phone'] !== 'N/A'): ?>
								<a href="tel:<?php echo preg_replace('/[^0-9+]/', '', $t['customer_phone']); ?>" 
								   class="text-sm font-black hover:text-blue-600 underline decoration-slate-300 underline-offset-2">
									<?php echo htmlspecialchars($t['customer_phone']); ?>
								</a>
							<?php else: ?>
								<span class="text-sm font-bold text-slate-400">No Phone</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
                <?php endforeach; ?>
            </div>
        </aside>

        <main class="flex-1 relative">
            <div class="map-search-container no-print">
                <div class="flex bg-white rounded-lg shadow-xl border border-slate-300 overflow-hidden p-1">
                    <input type="text" id="mapSearchInput" placeholder="Enter address or lat, lng..." 
                           class="flex-1 px-3 py-2 text-sm outline-none" onkeypress="if(event.key === 'Enter') searchMapLocation()">
                    <button onclick="searchMapLocation()" class="bg-slate-800 text-white px-3 flex items-center justify-center rounded-md hover:bg-slate-700">
                        <span class="material-icons text-sm">search</span>
                    </button>
                </div>
            </div>
            <div id="map"></div>
        </main>
    </div>

    <script>
        let map; let markers = {}; let selectedIds = new Set(); let searchMarker = null;
        const tickets = <?php echo json_encode(array_values($mapTickets)); ?>;
        const typeConfig = <?php echo json_encode($typeConfig); ?>;

        function initMap() {
            // Restore filters from localStorage before applying
            loadFilters();

            map = new google.maps.Map(document.getElementById("map"), { 
                zoom: 11, center: { lat: -41.01, lng: 175.51 }, mapTypeId: 'roadmap',
                styles: [{ featureType: "poi", elementType: "labels", stylers: [{ visibility: "off" }] }]
            });
            const bounds = new google.maps.LatLngBounds();
            
            tickets.forEach(t => {
                const pos = { lat: parseFloat(t.lat), lng: parseFloat(t.lng) };
                const cfg = typeConfig[t.type_label] || typeConfig['default'];
                const baseUrl = (t.ui_url || '').replace(/\/$/, '');
                const cleanPhone = (t.customer_phone || '').replace(/[^0-9+]/g, '');
                
                const dateObj = t.created_at ? new Date(t.created_at) : null;
                const formattedDate = dateObj ? dateObj.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'N/A';

                const phoneHtml = (t.customer_phone && t.customer_phone !== 'N/A') 
                    ? `<div class="flex items-center text-slate-900 mt-1">
                         <span class="material-icons text-[14px] mr-1 text-emerald-600">phone</span>
                         <a href="tel:${cleanPhone}" class="text-xs font-black underline hover:text-blue-600">${t.customer_phone}</a>
                       </div>`
                    : '';

                const marker = new google.maps.Marker({
                    position: pos, map: map,
                    label: { fontFamily: 'Material Icons', text: cfg.icon, color: 'white', fontSize: '14px' },
                    icon: { 
                        path: "M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z", 
                        fillColor: cfg.color, fillOpacity: 1, strokeWeight: 1, strokeColor: '#ffffff', scale: 1.8, labelOrigin: new google.maps.Point(12, 9) 
                    }
                });

               const info = new google.maps.InfoWindow({ content: `
					<div class="p-2 min-w-[240px] font-sans">
                        <div class="flex justify-between items-center mb-1">
						    <div class="text-[10px] font-black text-slate-400 uppercase">${t.priority} PRIORITY</div>
                            <div class="text-[9px] text-slate-400 font-bold italic uppercase">${formattedDate}</div>
                        </div>
						<a href="${baseUrl}/admin/tickets/opened--view?id=${t.ticket_id}" target="_blank" class="text-base font-bold text-blue-600 hover:underline block mb-0.5">${t.subject}</a>
						<a href="${baseUrl}/admin/customers/view?id=${t.customer_id}" target="_blank" class="text-sm font-black text-slate-900 hover:underline block mb-2">${t.customer_name}</a>
						<div class="space-y-1.5 mb-3">
							<div class="text-[11px] text-slate-600"><b>Agent:</b> ${t.assigned_to || 'Unassigned'}</div>
							<div class="text-xs text-slate-800"><b>Address:</b> ${t.service_address}</div>
                            ${phoneHtml}
						</div>
						<button onclick="toggleHighlight('${t.ticket_id}')" class="w-full text-[10px] bg-blue-600 text-white font-black py-2 rounded uppercase shadow-sm hover:bg-blue-700">
							Select for Dispatch
						</button>
					</div>` 
                });

                marker.addListener("click", () => {
                    closeAllInfoWindows();
                    info.open(map, marker);
                    scrollSidebarTo(t.ticket_id);
                });

                markers[t.ticket_id] = { marker, pos, ticket: t, infoWindow: info };
                bounds.extend(pos);
            });
            if (tickets.length) map.fitBounds(bounds);
            applyFilters();
        }

        function saveFilters() {
            const preferences = {
                agents: Array.from(document.querySelectorAll('.agent-checkbox:checked')).map(cb => cb.value),
                statuses: Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb => cb.value),
                types: Array.from(document.querySelectorAll('.type-checkbox:checked')).map(cb => cb.value),
                priorities: Array.from(document.querySelectorAll('.priority-checkbox:checked')).map(cb => cb.value),
                noAddressOnly: document.getElementById('noAddressOnlyToggle').checked
            };
            localStorage.setItem('ticketMapFilters', JSON.stringify(preferences));
        }

        function loadFilters() {
            const saved = localStorage.getItem('ticketMapFilters');
            if (!saved) return;
            const prefs = JSON.parse(saved);
            
            const setBoxes = (cls, values) => {
                if (!values) return;
                document.querySelectorAll('.' + cls).forEach(cb => {
                    cb.checked = values.includes(cb.value);
                });
            };

            setBoxes('agent-checkbox', prefs.agents);
            setBoxes('status-checkbox', prefs.statuses);
            setBoxes('type-checkbox', prefs.types);
            setBoxes('priority-checkbox', prefs.priorities);
            
            if (prefs.noAddressOnly !== undefined) {
                document.getElementById('noAddressOnlyToggle').checked = prefs.noAddressOnly;
            }
        }

        function searchMapLocation() {
            const input = document.getElementById('mapSearchInput').value;
            if (!input) return;
            const coordsRegex = /^(-?\d+(\.\d+)?),\s*(-?\d+(\.\d+)?)$/;
            const match = input.match(coordsRegex);
            if (match) {
                placeSearchMarker({ lat: parseFloat(match[1]), lng: parseFloat(match[3]) });
            } else {
                const geocoder = new google.maps.Geocoder();
                geocoder.geocode({ address: input }, (results, status) => {
                    if (status === "OK") placeSearchMarker(results[0].geometry.location);
                });
            }
        }

        function placeSearchMarker(location) {
            if (searchMarker) searchMarker.setMap(null);
            map.panTo(location); map.setZoom(17);
            searchMarker = new google.maps.Marker({ position: location, map: map, animation: google.maps.Animation.DROP });
        }

        function toggleHighlight(id) {
            const card = document.getElementById(`card-${id}`);
            const checkbox = document.getElementById(`check-${id}`);
            const m = markers[id];
            if (selectedIds.has(id)) {
                selectedIds.delete(id);
                if(card) card.classList.remove('selected');
                if(checkbox) checkbox.checked = false;
                if(m) m.marker.setOptions({ icon: { ...m.marker.icon, strokeColor: '#ffffff', strokeWeight: 1 } });
            } else {
                selectedIds.add(id);
                if(card) card.classList.add('selected');
                if(checkbox) checkbox.checked = true;
                if(m) m.marker.setOptions({ icon: { ...m.marker.icon, strokeColor: '#3b82f6', strokeWeight: 4 } });
            }
            document.getElementById('selectedCount').innerText = selectedIds.size;
        }

        function applyFilters() {
            const search = document.getElementById('tSearch').value.toLowerCase();
            const selAgents = Array.from(document.querySelectorAll('.agent-checkbox:checked')).map(cb => cb.value);
            const selStatuses = Array.from(document.querySelectorAll('.status-checkbox:checked')).map(cb => cb.value);
            const selTypes = Array.from(document.querySelectorAll('.type-checkbox:checked')).map(cb => cb.value);
            const selPriorities = Array.from(document.querySelectorAll('.priority-checkbox:checked')).map(cb => cb.value);
            const noAddressOnly = document.getElementById('noAddressOnlyToggle').checked;
            
            let visibleMarkers = 0;
            let noAddressCount = 0;

            document.querySelectorAll('.ticket-card').forEach(card => {
                const m = markers[card.id.replace('card-', '')];
                const hasAddr = card.dataset.hasAddress === '1';
                
                let matches = selAgents.includes(card.dataset.agent) && selStatuses.includes(card.dataset.status) && 
                              selTypes.includes(card.dataset.type) && selPriorities.includes(card.dataset.priority) &&
                              card.innerText.toLowerCase().includes(search);
                
                if (noAddressOnly && hasAddr) {
                    matches = false;
                }

                card.style.display = matches ? 'block' : 'none';
                if(m) m.marker.setVisible(matches);
                
                if (selAgents.includes(card.dataset.agent) && selStatuses.includes(card.dataset.status) && 
                    selTypes.includes(card.dataset.type) && selPriorities.includes(card.dataset.priority) &&
                    card.innerText.toLowerCase().includes(search)) {
                    if (hasAddr) visibleMarkers++;
                    else noAddressCount++;
                }
            });

            document.getElementById('headerMarkerCount').innerText = visibleMarkers;
            document.getElementById('headerNoAddressCount').innerText = noAddressCount;
            
            saveFilters(); // Persist changes
        }

        function scrollSidebarTo(id) {
            const card = document.getElementById(`card-${id}`);
            if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                card.classList.add('flash-highlight');
                setTimeout(() => card.classList.remove('flash-highlight'), 1500);
            }
        }

        function handleSidebarCardClick(id, event) {
            const m = markers[id];
            if (m) { map.panTo(m.pos); map.setZoom(16); closeAllInfoWindows(); m.infoWindow.open(map, m.marker); }
        }

        function toggleAllFilters() {
            const container = document.getElementById('filterContainer');
            const btn = document.getElementById('globalToggle');
            const isHidden = container.classList.toggle('hidden');
            document.querySelectorAll('.filter-group').forEach(g => g.classList.toggle('minimized', isHidden));
            btn.innerText = isHidden ? 'RESTORE FILTERS' : 'MINIMISE FILTERS';
        }

        function closeAllInfoWindows() { Object.values(markers).forEach(m => m.infoWindow.close()); }

        function toggleGroup(cls) {
            const cbs = document.querySelectorAll('.' + cls);
            const allChecked = Array.from(cbs).every(c => c.checked);
            cbs.forEach(c => c.checked = !allChecked);
            applyFilters();
        }

        function getDirections() {
            const selArr = Array.from(selectedIds);
            if (!selArr.length) return alert("Select tickets.");
            const dest = markers[selArr[selArr.length-1]].ticket;
            let url = `https://www.google.com/maps/dir/?api=1&destination=${dest.lat},${dest.lng}`;
            if (selArr.length > 1) {
                const wps = selArr.slice(0, -1).map(id => markers[id].ticket.lat + ',' + markers[id].ticket.lng);
                url += `&waypoints=${encodeURIComponent(wps.join('|'))}`;
            }
            window.open(url, '_blank');
        }

        function printSelected() {
            if (!selectedIds.size) return alert("Select tickets.");
            const win = window.open('', '_blank');
            let content = '<h2>Dispatch List</h2><table border="1" style="border-collapse:collapse; width:100%"><tr><th>ID</th><th>Customer</th><th>Subject</th><th>Address</th><th>Created</th></tr>';
            selectedIds.forEach(id => {
                const t = markers[id].ticket;
                content += `<tr><td>#${t.ticket_id}</td><td><b>${t.customer_name}</b><br>${t.customer_phone}</td><td>${t.subject}</td><td>${t.service_address}</td><td>${t.created_at}</td></tr>`;
            });
            win.document.write(content + '</table>');
            win.document.close(); win.print();
        }

        document.querySelectorAll('.filter-cb').forEach(cb => cb.addEventListener('change', applyFilters));
        document.getElementById('tSearch').addEventListener('input', applyFilters);
    </script>
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo $googleApiKey; ?>&callback=initMap"></script>
</body>
</html>
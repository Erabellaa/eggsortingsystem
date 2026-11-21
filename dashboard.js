if (
    localStorage.getItem("eggAdminLoggedIn")
    !== "true"
) {

    window.location.href = "index.html";

}


let eggData = {

    total: 0,

    small: 0,

    medium: 0,

    large: 0,

    xl: 0,
    rejected: 0

};

let batchLimit = Number(localStorage.getItem("eggBatchLimit")) || 30;

let eggRecords = [];

let sortingInterval = null;
let workflowTimeout = null;
let sortingRunning = false;
let currentWorkflowStep = -1;

const totalEggs =
    document.getElementById("totalEggs");

const smallEggs =
    document.getElementById("smallEggs");

const mediumEggs =
    document.getElementById("mediumEggs");

const largeEggs =
    document.getElementById("largeEggs");

const xlEggs =
    document.getElementById("xlEggs");

const activityTable =
    document.getElementById("activityTable");

const reportTable =
    document.getElementById("reportTable");

const machineStatus =
    document.getElementById("machineStatus");

const sortingStatus =
    document.getElementById("sortingStatus");

const eggsPerMinute =
    document.getElementById("eggsPerMinute");


const navLinks =
    document.querySelectorAll(".nav-link");

const sections =
    document.querySelectorAll(".content-section");


function showSection(sectionId) {

    sections.forEach(section => {

        section.classList.remove(
            "active-section"
        );

    });


    const selected =
        document.getElementById(sectionId);


    if (selected) {

        selected.classList.add(
            "active-section"
        );

    }


    navLinks.forEach(link => {

        link.classList.remove("active");

        if (
            link.dataset.section
            === sectionId
        ) {

            link.classList.add("active");

        }

    });


    window.scrollTo({
        top: 0,
        behavior: "smooth"
    });

}


navLinks.forEach(link => {

    link.addEventListener(
        "click",
        function(event) {

            event.preventDefault();

            showSection(
                this.dataset.section
            );

        }
    );

});

document
    .querySelector(".view-report")
    .addEventListener("click", function() {

        showSection("reports");

    });

const sidebar =
    document.getElementById("sidebar");

const menuButton =
    document.getElementById("menuButton");


menuButton.addEventListener(
    "click",
    function() {

        sidebar.classList.toggle("open");

    }
);

const API_URL = "dashboard.php?api=load";

async function apiRequest(action, data = {}) {
    const response = await fetch("dashboard.php?api=" + encodeURIComponent(action), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action, ...data })
    });
    const result = await response.json();
    if (!response.ok || result.error) throw new Error(result.error || "Database request failed.");
    return result;
}

function applyServerData(payload) {
    const batch = payload.batch;
    if (batch) {
        eggData = {
            total: Number(batch.total_eggs || 0),
            small: Number(batch.small_eggs || 0),
            medium: Number(batch.medium_eggs || 0),
            large: Number(batch.large_eggs || 0),
            xl: Number(batch.xl_eggs || 0),
            rejected: Number(batch.rejected_eggs || 0)
        };
        eggRecords = (payload.records || []).map(r => ({
            id: r.id, weight: Number(r.weight), category: r.category,
            qualityStatus: r.qualityStatus, direction: r.direction, time: r.time
        }));
    } else {
        eggData = { total: 0, small: 0, medium: 0, large: 0, xl: 0, rejected: 0 };
        eggRecords = [];
    }
    if (payload.batch_limit) batchLimit = Number(payload.batch_limit);
    const limitInput = document.getElementById("eggLimit");
    if (limitInput) limitInput.value = batchLimit;
    updateDashboard();
}

async function loadData() {
    try {
        const response = await fetch(API_URL, { cache: "no-store" });
        const payload = await response.json();
        if (!response.ok || payload.error) throw new Error(payload.error || "Unable to load database records.");
        applyServerData(payload);
    } catch (error) {
        showNotification("Database connection error: " + error.message);
        console.error(error);
    }
}

loadData();

function saveData() {
    // Sorting records are saved directly to MySQL through dashboard.php API.
}


function updateDashboard() {

    totalEggs.textContent =
        eggData.total;

    smallEggs.textContent =
        eggData.small;

    mediumEggs.textContent =
        eggData.medium;

    largeEggs.textContent =
        eggData.large;

    xlEggs.textContent =
        eggData.xl;


    document.getElementById(
        "liveSmall"
    ).textContent =
        eggData.small;

    document.getElementById(
        "liveMedium"
    ).textContent =
        eggData.medium;

    document.getElementById(
        "liveLarge"
    ).textContent =
        eggData.large;

    document.getElementById(
        "liveXL"
    ).textContent =
        eggData.xl;

    const rejectedCount = document.getElementById("rejectedEggs");
    const liveRejected = document.getElementById("liveRejected");
    if (rejectedCount) rejectedCount.textContent = eggData.rejected || 0;
    if (liveRejected) liveRejected.textContent = eggData.rejected || 0;

    const batchProgress = document.getElementById("batchProgress");
    if (batchProgress) batchProgress.textContent = `${eggData.total} / ${batchLimit}`;

    updatePercentages();

    updateActivityTable();

    updateReportTable();

}

function updatePercentages() {

    const total =
        eggData.total;


    if (total === 0) {

        setProgress(
            "small",
            0
        );

        setProgress(
            "medium",
            0
        );

        setProgress(
            "large",
            0
        );

        setProgress(
            "xl",
            0
        );

        return;

    }


    const small =
        Math.round(
            (eggData.small / total) * 100
        );

    const medium =
        Math.round(
            (eggData.medium / total) * 100
        );

    const large =
        Math.round(
            (eggData.large / total) * 100
        );

    const xl =
        Math.round(
            (eggData.xl / total) * 100
        );


    setProgress("small", small);

    setProgress("medium", medium);

    setProgress("large", large);

    setProgress("xl", xl);

}


function setProgress(
    type,
    percentage
) {

    document.getElementById(
        type + "Progress"
    ).style.width =
        percentage + "%";


    document.getElementById(
        type + "Percent"
    ).textContent =
        percentage + "%";

}

const workflowStepNames = [
    "Power On & Initialize", "Place Egg on Conveyor", "Detect Egg Presence",
    "Move Conveyor", "Measure Egg Weight", "Check Egg Quality",
    "Compare Weight Standards", "Activate Sorting Servo",
    "Direct Egg to Tray", "Display Sorting Result", "Ready for Next Egg"
];

function updateWorkflow(step, state = "active") {
    currentWorkflowStep = step;
    document.querySelectorAll(".workflow-step").forEach((item, index) => {
        item.classList.toggle("is-active", index === step && state === "active");
        item.classList.toggle("is-complete", index < step || (index === step && state === "complete"));
    });
    const indicator = document.getElementById("workflowIndicator");
    if (indicator) indicator.textContent = state === "stopped" ? "STOPPED" : (state === "complete" ? "CYCLE COMPLETE" : (step < 0 ? "READY" : `STEP ${step + 1} OF 11`));
    if (step >= 0 && sortingStatus && sortingRunning) sortingStatus.textContent = workflowStepNames[step];
}

function runEggWorkflow() {
    if (!sortingRunning) return;
    const stepDuration = 450;
    let step = 0;
    const advance = () => {
        if (!sortingRunning) return;
        updateWorkflow(step, "active");
        if (step < 10) {
            step++;
            workflowTimeout = setTimeout(advance, stepDuration);
            return;
        }
        addEgg();
        if (!sortingRunning) return;
        updateWorkflow(10, "complete");
        workflowTimeout = setTimeout(() => {
            if (!sortingRunning) return;
            updateWorkflow(-1);
            runEggWorkflow();
        }, 350);
    };
    advance();
}

async function addEgg() {
    // DEMO MODE: replace these generated readings with actual Arduino sensor values
    // when the physical sensors and controller are connected.
    const weight = Math.floor(Math.random() * 31) + 40;
    const qualityRoll = Math.random();
    let qualityStatus = "Accepted";
    if (qualityRoll < 0.07) qualityStatus = "Cracked - Rejected";
    else if (qualityRoll < 0.12) qualityStatus = "Suspected rotten - Rejected";

    try {
        const result = await apiRequest("add_egg", { weight, quality_status: qualityStatus });
        await loadData();
        if (result.batch_complete) {
            stopSortingMachine("Batch limit reached", true);
            showNotification(`Batch complete: ${batchLimit} eggs inspected. Sorting stopped automatically.`);
        }
    } catch (error) {
        stopSortingMachine("Database save failed");
        showNotification("Could not save egg: " + error.message);
        console.error(error);
    }
}


const movingEgg =
    document.getElementById(
        "movingEgg"
    );

const largeMovingEgg =
    document.getElementById(
        "largeMovingEgg"
    );


async function startSortingMachine() {
    if (sortingRunning) return;
    if (!Number.isInteger(batchLimit) || batchLimit < 1) {
        showNotification("Please enter a valid batch limit."); return;
    }
    if (eggData.total >= batchLimit) {
        showNotification("Batch limit reached. Reset the batch or increase the limit."); return;
    }
    if (document.getElementById("automaticSorting")?.checked === false) {
        showNotification("Enable Automatic Sorting in Admin Settings first."); return;
    }
    try {
        await apiRequest("start");
        sortingRunning = true;
        movingEgg.classList.add("moving");
        largeMovingEgg.classList.add("active");
        machineStatus.innerHTML = '<span></span> LIVE';
        sortingStatus.textContent = "Sorting";
        updateWorkflow(0);
        runEggWorkflow();
    } catch (error) {
        showNotification("Unable to start machine: " + error.message);
    }
}


function stopSortingMachine(reason = "Stopped", alreadyStoppedInDatabase = false) {
    const wasRunning = sortingRunning;
    sortingRunning = false;
    if (wasRunning && !alreadyStoppedInDatabase) {
        apiRequest("stop", { reason }).catch(error => {
            showNotification("Could not update machine status: " + error.message);
        });
    }
    clearInterval(sortingInterval);
    sortingInterval = null;
    clearTimeout(workflowTimeout);
    workflowTimeout = null;
    updateWorkflow(-1, "stopped");

    movingEgg.classList.remove("moving");
    largeMovingEgg.classList.remove("active");

    const statusLabel = reason === "Batch limit reached" ? "LIMIT REACHED" : "STOPPED";
    machineStatus.innerHTML = `<span style="background:#d9534f"></span> ${statusLabel}`;
    sortingStatus.textContent = reason;
}

async function resetSorting() {
    stopSortingMachine("Reset");
    try {
        await apiRequest("reset");
        await loadData();
        machineStatus.innerHTML = '<span></span> READY';
        sortingStatus.textContent = "Ready";
        updateWorkflow(-1);
        showNotification("A new empty batch is ready. Previous records remain saved in MySQL.");
    } catch (error) {
        showNotification("Could not reset batch: " + error.message);
    }
}


document
    .getElementById("startSorting")
    .addEventListener(
        "click",
        startSortingMachine
    );


document
    .getElementById("stopSorting")
    .addEventListener(
        "click",
        stopSortingMachine
    );


document
    .getElementById("resetSorting")
    .addEventListener(
        "click",
        resetSorting
    );

function updateActivityTable() {

    if (
        eggRecords.length === 0
    ) {

        activityTable.innerHTML = `

            <tr>

                <td>--:--</td>

                <td>EGG-0000</td>

                <td>-- g</td>

                <td>Waiting</td>

                <td>
                    <span class="status waiting">
                        Waiting
                    </span>
                </td>

            </tr>

        `;

        return;

    }


    activityTable.innerHTML =
        eggRecords
        .slice(0, 8)
        .map(
            record => `

            <tr>

                <td>
                    ${record.time}
                </td>

                <td>
                    ${record.id}
                </td>

                <td>
                    ${record.weight} g
                </td>

                <td>
                    ${record.category === "Rejected" ? "REJECTED" : record.category.toUpperCase()}
                </td>

                <td>
                    <span class="status ${record.category === "Rejected" ? "rejected" : "sorted"}">
                        ${record.qualityStatus || "Accepted"}
                    </span>

                </td>

            </tr>

        `
        )
        .join("");

}

function updateReportTable() {

    const reportTotal =
        document.getElementById(
            "reportTotal"
        );


    reportTotal.textContent =
        eggData.total;


    document.getElementById(
        "reportDate"
    ).textContent =
        new Date().toLocaleDateString();


    if (
        eggRecords.length === 0
    ) {

        reportTable.innerHTML = `

            <tr>

                <td colspan="6">
                    No sorting records available.
                </td>

            </tr>

        `;

        return;

    }


    reportTable.innerHTML =
        eggRecords
        .map(
            (record, index) => `

            <tr>

                <td>
                    ${index + 1}
                </td>

                <td>
                    ${record.id}
                </td>

                <td>
                    ${record.weight} g
                </td>

                <td>
                    ${record.category === "Rejected" ? "REJECTED" : record.category.toUpperCase()}
                </td>

                <td>${record.qualityStatus || "Accepted"}</td>

                <td>
                    ${record.time}
                </td>

            </tr>

        `
        )
        .join("");

}


let previousTotal =
    eggData.total;

setInterval(
    function() {

        const currentTotal =
            eggData.total;


        const difference =
            currentTotal
            - previousTotal;


        eggsPerMinute.textContent =
            difference * 30;


        previousTotal =
            currentTotal;

    },
    2000
);

document
    .getElementById("exportReport")
    .addEventListener(
        "click",
        function() {

            if (
                eggRecords.length === 0
            ) {

                showNotification(
                    "There are no records to export."
                );

                return;

            }


            let csv =
                "Egg ID,Weight,Category,Quality Status,Time\n";


            eggRecords.forEach(
                record => {

                    csv += `${record.id},${record.weight} g,${record.category},${record.qualityStatus || "Accepted"},${record.time}\n`;

                }
            );


            const blob =
                new Blob(
                    [csv],
                    {
                        type:
                            "text/csv"
                    }
                );


            const url =
                URL.createObjectURL(
                    blob
                );


            const link =
                document.createElement(
                    "a"
                );


            link.href = url;

            link.download =
                "YHAN-KIEL-Egg-Sorting-Report.csv";


            link.click();


            URL.revokeObjectURL(
                url
            );


            showNotification(
                "Report exported successfully."
            );

        }
    );


function showNotification(text) {

    const popup =
        document.getElementById(
            "notificationPopup"
        );

    const notificationText =
        document.getElementById(
            "notificationText"
        );


    notificationText.textContent =
        text;


    popup.classList.add(
        "show"
    );


    setTimeout(
        function() {

            popup.classList.remove(
                "show"
            );

        },
        2500
    );

}

document
    .getElementById("saveProfile")
    .addEventListener(
        "click",
        function() {

            const name =
                document.getElementById(
                    "adminName"
                ).value;


            localStorage.setItem(
                "adminName",
                name
            );


            showNotification(
                "Administrator profile saved."
            );

        }
    );


document
    .getElementById("changePassword")
    .addEventListener(
        "click",
        function() {

            const current =
                document.getElementById(
                    "currentPassword"
                ).value;

            const newPassword =
                document.getElementById(
                    "newPassword"
                ).value;

            const confirmPassword =
                document.getElementById(
                    "confirmPassword"
                ).value;


            if (
                current !== "admin123"
            ) {

                showNotification(
                    "Current password is incorrect."
                );

                return;

            }


            if (
                newPassword.length < 6
            ) {

                showNotification(
                    "New password must have at least 6 characters."
                );

                return;

            }


            if (
                newPassword !== confirmPassword
            ) {

                showNotification(
                    "Passwords do not match."
                );

                return;

            }


            showNotification(
                "Password changed successfully."
            );


            document.getElementById(
                "currentPassword"
            ).value = "";

            document.getElementById(
                "newPassword"
            ).value = "";

            document.getElementById(
                "confirmPassword"
            ).value = "";

        }
    );

document
    .getElementById("logoutBtn")
    .addEventListener(
        "click",
        function() {

            const confirmLogout =
                confirm(
                    "Are you sure you want to logout?"
                );


            if (
                confirmLogout
            ) {

                localStorage.removeItem(
                    "eggAdminLoggedIn"
                );

                window.location.href =
                    "index.html";

            }

        }
    );

sortingStatus.textContent =
    "Ready";

machineStatus.innerHTML =
    '<span></span> READY';

const eggLimitInput = document.getElementById("eggLimit");
if (eggLimitInput) {
    eggLimitInput.value = batchLimit;
    eggLimitInput.addEventListener("change", async function () {
        const value = Number(this.value);
        if (!Number.isInteger(value) || value < 1 || value > 10000) {
            this.value = batchLimit;
            showNotification("Enter a whole-number limit from 1 to 10,000.");
            return;
        }
        if (sortingRunning) {
            this.value = batchLimit;
            showNotification("Stop sorting before changing the batch limit.");
            return;
        }
        try {
            await apiRequest("set_limit", { batch_limit: value });
            batchLimit = value;
            updateDashboard();
            showNotification(`Batch limit saved to database: ${batchLimit} eggs.`);
        } catch (error) {
            this.value = batchLimit;
            showNotification("Could not save batch limit: " + error.message);
        }
    });
}

const loginForm = document.getElementById("loginForm");
const username = document.getElementById("username");
const password = document.getElementById("password");
const message = document.getElementById("message");

const togglePassword = document.getElementById("togglePassword");
const forgotPassword = document.getElementById("forgotPassword");


togglePassword.addEventListener("click", function () {

    if (password.type === "password") {

        password.type = "text";

        this.innerHTML =
            '<i class="fa-solid fa-eye-slash"></i>';

    } else {

        password.type = "password";

        this.innerHTML =
            '<i class="fa-solid fa-eye"></i>';
    }

});


loginForm.addEventListener("submit", function (event) {

    event.preventDefault();

    const enteredUsername =
        username.value.trim();

    const enteredPassword =
        password.value.trim();


    const adminUsername = "admin";
    const adminPassword = "admin123";


    message.className = "message";
    message.textContent = "";


    if (
        enteredUsername === "" ||
        enteredPassword === ""
    ) {

        message.className = "message error";

        message.textContent =
            "Please enter your username and password.";

        return;
    }


    if (
        enteredUsername === adminUsername &&
        enteredPassword === adminPassword
    ) {

        message.className = "message success";

        message.textContent =
            "Login successful! Opening dashboard...";


        localStorage.setItem(
            "eggAdminLoggedIn",
            "true"
        );

        setTimeout(function () {

            window.location.href = "dashboard.php";

        }, 1000);

    }

    else {

        message.className = "message error";

        message.textContent =
            "Invalid username or password.";

        password.value = "";
    }

});


forgotPassword.addEventListener("click", function (event) {

    event.preventDefault();

    alert(
        "Please contact the system administrator to reset your password."
    );

});
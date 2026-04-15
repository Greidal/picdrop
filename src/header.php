<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Kamerarsch'; ?></title>
    <link rel="icon" href="favicon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #111;
            --card-bg: #1a1a1a;
            --text: #eee;
            --text-muted: #888;
            --primary: #ff0055;
            --primary-glow: rgba(255, 0, 85, 0.4);
            --success: #00ff88;
            --border: #333;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 20px;
            min-height: 90vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        h1,
        h2,
        h3 {
            font-weight: 300;
            margin-top: 0;
        }

        h1 {
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        a {
            color: var(--text);
            text-decoration: none;
            transition: 0.2s;
        }

        a:hover {
            color: var(--primary);
        }

        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        .card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 15px;
            border: 1px solid var(--border);
            margin-bottom: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }

        .flex-between {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        input[type="file"],
        select {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #222;
            color: white;
            font-size: 1rem;
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .toggle-switch {
            display: flex;
            align-items: center;
            cursor: pointer;
            margin-bottom: 10px;
        }

        .toggle-switch input {
            display: none;
        }

        .slider {
            width: 40px;
            height: 20px;
            background: #444;
            border-radius: 20px;
            position: relative;
            transition: 0.3s;
            margin-right: 10px;
        }

        .slider::before {
            content: "";
            position: absolute;
            width: 16px;
            height: 16px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: 0.3s;
        }

        input:checked+.slider {
            background: var(--primary);
        }

        input:checked+.slider::before {
            transform: translateX(20px);
        }

        .btn {
            display: inline-block;
            padding: 12px 25px;
            border-radius: 50px;
            cursor: pointer;
            text-align: center;
            font-weight: bold;
            border: none;
            transition: transform 0.1s;
            text-decoration: none;
        }

        .btn:active {
            transform: scale(0.96);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 0 15px var(--primary-glow);
            font-size: 1.1rem;
            width: 100%;
            max-width: 300px;
        }

        .btn-secondary {
            background: transparent;
            border: 2px solid #444;
            color: #ccc;
        }

        .btn-danger {
            background: #ff4444;
            color: white;
            padding: 5px 10px;
            font-size: 0.8rem;
            border-radius: 5px;
        }

        .btn-small {
            padding: 8px 15px;
            font-size: 0.9rem;
            width: auto;
        }

        .hidden {
            display: none !important;
        }

        .text-center {
            text-align: center;
        }

        .msg {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
            text-align: center;
        }

        .msg.success {
            background: rgba(0, 255, 136, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .msg.error {
            background: rgba(255, 68, 68, 0.2);
            color: #ff4444;
            border: 1px solid #ff4444;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            display: none;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 15px;
            border: 1px solid var(--primary);
            max-width: 400px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            text-align: center;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
            vertical-align: middle;
            margin-right: 10px;
        }
    </style>
</head>

<body>
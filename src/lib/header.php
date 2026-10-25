<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle ?? PAGE_TITLE); ?></title>
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
        input[type="search"],
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

        label {
            color: #ccc;
        }

        small,
        .muted {
            color: var(--text-muted);
        }

        /* Small icon actions (dashboard, settings). Plain, without button chrome. */
        .icon-link {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            min-width: 44px;
            padding: 4px 2px;
            color: #bbb;
            background: none;
            border: none;
            cursor: pointer;
            font: inherit;
            border-radius: 8px;
        }

        .icon-link i {
            font-size: 1.05rem;
        }

        .icon-link span {
            font-size: 0.62rem;
            color: var(--text-muted);
            letter-spacing: 0.2px;
            white-space: nowrap;
        }

        .icon-link:hover,
        .icon-link:focus-visible {
            color: var(--primary);
            background: #222;
        }

        .icon-btn {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .icon-btn:hover,
        .icon-btn:focus-visible {
            color: var(--primary);
            background: #222;
        }

        .icon-btn.danger:hover {
            color: #ff4444;
        }

        /* Collapsible sections ("Register") */
        details.section {
            margin-bottom: 14px;
        }

        details.section > summary {
            list-style: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 4px;
            font-size: 1.05rem;
            user-select: none;
            border-bottom: 1px solid var(--border);
        }

        details.section > summary::-webkit-details-marker {
            display: none;
        }

        details.section > summary::before {
            content: "\203A";
            font-size: 1.4rem;
            line-height: 1;
            color: var(--text-muted);
            transition: transform 0.2s;
            width: 12px;
        }

        details.section[open] > summary::before {
            transform: rotate(90deg);
        }

        .count-pill {
            background: #2a2a2a;
            color: #aaa;
            border-radius: 20px;
            padding: 1px 9px;
            font-size: 0.8rem;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #222;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 4px 6px 4px 12px;
            font-size: 0.85rem;
        }

        .chip.selected {
            border-color: var(--success);
            background: rgba(0, 255, 136, 0.12);
        }

        .row-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .row-form input {
            margin: 0;
        }

        .btn-inline {
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 0.95rem;
            width: auto;
            white-space: nowrap;
        }

        input[type="range"] {
            width: 100%;
            accent-color: var(--primary);
        }

        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .card {
                padding: 16px;
                border-radius: 12px;
            }

            h1 {
                font-size: 1.6rem;
            }

            .modal-content {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
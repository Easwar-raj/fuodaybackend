<!DOCTYPE html>
<html>
    <head>
        <title>Payslip - {{ $employee['name'] }}</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 13px; }
            .header, .footer { text-align: center; }
            .content { margin-top: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th, td { border: 1px solid #000; padding: 6px; text-align: left; }
            h2 { text-align: center; }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>Payslip for {{ $payslip['month'] }}</h2>
        </div>

        <div class="content">
            <strong>Employee Details:</strong><br>
            Name: {{ $employee['name'] }}<br>
            Emp ID: {{ $employee['emp_id'] }}<br>
            Designation: {{ $employee['designation'] }}<br>
            Date of Joining: {{ $employee['date_of_joining'] }}<br>

            <br><strong>Salary Details:</strong>
            <table>
                <thead><tr><th>Earnings</th><th>Amount</th></tr></thead>
                <tbody>
                    @foreach($salary_components['Earnings'] as $name => $value)
                    <tr><td>{{ $name }}</td><td>{{ number_format($value, 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            <table>
                <thead><tr><th>Deductions</th><th>Amount</th></tr></thead>
                <tbody>
                    @foreach($salary_components['Deductions'] as $name => $value)
                    <tr><td>{{ $name }}</td><td>{{ number_format($value, 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            <br><strong>Summary:</strong><br>
            Gross Salary: {{ number_format($payslip['gross'], 2) }}<br>
            Total Deductions: {{ number_format($payslip['total_deductions'], 2) }}<br>
            Net Salary: {{ number_format($payslip['total_salary'], 2) }}<br>
            In Words: {{ $payslip['total_salary_word'] }}<br>
        </div>

        <div class="footer">
            <br><br>
            <em>This is a system-generated payslip and does not require a signature.</em>
        </div>
    </body>
</html>
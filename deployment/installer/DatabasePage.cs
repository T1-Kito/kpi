sealed partial class SetupForm
{
    readonly TextBox sqlServerName=new(){PlaceholderText=@"Máy chủ (vd: localhost\SQLEXPRESS)",AccessibleName="Máy chủ SQL Server"};
    readonly TextBox sqlDatabaseName=new(){PlaceholderText="Tên cơ sở dữ liệu",AccessibleName="Tên cơ sở dữ liệu"};
    readonly TextBox sqlUsername=new(){PlaceholderText="Tài khoản",AccessibleName="Tài khoản SQL Server"};
    readonly TextBox sqlPassword=new(){PlaceholderText="Mật khẩu",UseSystemPasswordChar=true,AccessibleName="Mật khẩu SQL Server"};
    readonly ComboBox sqlDatabaseMode=new(){DropDownStyle=ComboBoxStyle.DropDownList,Width=400};
    readonly CheckBox sqlSetupConsent=new(){Text="Tôi xác nhận cơ sở dữ liệu được chọn dành cho cài đặt mới",AutoSize=true,MaximumSize=new Size(570,0)};
    readonly CheckBox sqlResumeEmpty=new(){Text="Khôi phục lần cài gián đoạn (chỉ khi chưa có dữ liệu)",AutoSize=true,MaximumSize=new Size(570,0)};

    void RenderDatabasePage()
    {
        WizardText("Chọn nơi lưu trữ dữ liệu");
        var choices=new TableLayoutPanel{Width=580,Height=76,ColumnCount=2,RowCount=1,Margin=new Padding(0,0,0,14)};
        choices.ColumnStyles.Add(new ColumnStyle(SizeType.Percent,50));choices.ColumnStyles.Add(new ColumnStyle(SizeType.Percent,50));
        var sqlite=new Button{Text="SQLite\nLưu trữ trên máy tính này",Dock=DockStyle.Fill,FlatStyle=FlatStyle.Flat,TextAlign=ContentAlignment.MiddleLeft,Padding=new Padding(10),BackColor=Color.White,Margin=new Padding(0,0,5,0),AccessibleName="Chọn SQLite"};
        var sql=new Button{Text="SQL Server\nKết nối máy chủ dữ liệu",Dock=DockStyle.Fill,FlatStyle=FlatStyle.Flat,TextAlign=ContentAlignment.MiddleLeft,Padding=new Padding(10),BackColor=Color.White,Margin=new Padding(5,0,0,0),AccessibleName="Chọn SQL Server"};
        choices.Controls.Add(sqlite,0,0);choices.Controls.Add(sql,1,0);wizardBody.Controls.Add(choices);
        var config=new TableLayoutPanel{Width=580,Height=178,ColumnCount=2,RowCount=4,Padding=new Padding(14,10,14,14),BackColor=Color.FromArgb(244,246,251),Margin=Padding.Empty};
        config.ColumnStyles.Add(new ColumnStyle(SizeType.Percent,50));config.ColumnStyles.Add(new ColumnStyle(SizeType.Percent,50));
        config.RowStyles.Add(new RowStyle(SizeType.Absolute,26));
        for(int i=0;i<3;i++)config.RowStyles.Add(new RowStyle(SizeType.Percent,33.333f));
        var caption=new Label{Text="CẤU HÌNH KẾT NỐI",AutoSize=true,ForeColor=Color.FromArgb(100,116,139),Font=new Font("Segoe UI",9),Margin=new Padding(0,0,0,6)};
        config.Controls.Add(caption,0,0);config.SetColumnSpan(caption,2);
        foreach(var field in new[]{sqlServerName,sqlDatabaseName,sqlUsername,sqlPassword}){field.Dock=DockStyle.Fill;field.Margin=new Padding(0,2,0,6);field.BackColor=Color.White;}
        sqlDatabaseName.Margin=new Padding(0,2,5,6);sqlUsername.Margin=new Padding(5,2,0,6);
        config.Controls.Add(sqlServerName,0,1);config.SetColumnSpan(sqlServerName,2);
        config.Controls.Add(sqlDatabaseName,0,2);config.Controls.Add(sqlUsername,1,2);
        config.Controls.Add(sqlPassword,0,3);config.SetColumnSpan(sqlPassword,2);
        wizardBody.Controls.Add(config);
        if(sqlAuth.Items.Count==0){sqlAuth.Items.AddRange(new object[]{"Tài khoản SQL Server","Tài khoản Windows hiện tại"});sqlAuth.SelectedIndex=0;}
        var options=new FlowLayoutPanel{Width=580,AutoSize=true,AutoSizeMode=AutoSizeMode.GrowAndShrink,FlowDirection=FlowDirection.TopDown,WrapContents=false,Margin=new Padding(0,10,0,12)};
        if(sqlDatabaseMode.Items.Count==0){sqlDatabaseMode.Items.AddRange(new object[]{"Dùng database trống có sẵn","Tạo database mới (cần quyền trên server)"});sqlDatabaseMode.SelectedIndex=0;}
        options.Controls.Add(sqlDatabaseMode);options.Controls.Add(sqlSetupConsent);options.Controls.Add(sqlResumeEmpty);
        sqlAuth.Width=340;options.Controls.Add(sqlAuth);options.Controls.Add(sqlTrust);
        var test=new Button{Text="Kiểm tra kết nối",AutoSize=true};test.Click+=async(_,_)=>await CheckSqlConnection(test);options.Controls.Add(test);options.Controls.Add(sqlResult);wizardBody.Controls.Add(options);
        sqlAuth.SelectedIndexChanged-=SqlAuthChanged;sqlAuth.SelectedIndexChanged+=SqlAuthChanged;SqlAuthChanged(null,EventArgs.Empty);
        void SelectDatabase(int index)
        {
            databaseType.SelectedIndex=index;
            sqlite.FlatAppearance.BorderColor=index==0?Color.FromArgb(37,99,235):Color.FromArgb(203,213,225);
            sql.FlatAppearance.BorderColor=index==1?Color.FromArgb(37,99,235):Color.FromArgb(203,213,225);
            sqlite.FlatAppearance.BorderSize=index==0?2:1;sql.FlatAppearance.BorderSize=index==1?2:1;
            config.Visible=index==1;
            options.Visible=index==1;
        }
        sqlite.Click+=(_,_)=>SelectDatabase(0);sql.Click+=(_,_)=>SelectDatabase(1);
        SelectDatabase(databaseType.SelectedIndex);
    }

    void SqlAuthChanged(object? sender,EventArgs e){sqlUsername.Enabled=sqlPassword.Enabled=sqlAuth.SelectedIndex==0;sqlResult.Text="";}
}

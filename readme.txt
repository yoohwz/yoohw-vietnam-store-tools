=== Vietnam Store Toolkit for WooCommerce ===
Contributors: yoohw, baonguyen0310
Tags: woocommerce, vietnam, vietqr, checkout, vat invoice
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Địa chỉ Việt Nam, VietQR, hóa đơn GTGT, chuẩn hóa số điện thoại và công cụ vận chuyển cho WooCommerce.

== Description ==

Vietnam Store Toolkit for WooCommerce giúp cửa hàng WooCommerce bán hàng tại Việt Nam với dữ liệu địa chỉ hai cấp, yêu cầu hóa đơn GTGT, chuyển khoản VietQR, chuẩn hóa số điện thoại và công cụ vận chuyển trong trang quản trị.

Plugin giữ nguyên mô hình dữ liệu đơn hàng và khách hàng của WooCommerce, đồng thời điều chỉnh trang thanh toán, My Account, cài đặt cửa hàng và màn hình quản trị để phù hợp hơn với quy trình thương mại tại Việt Nam.

= Tính năng chính =

* Danh sách tỉnh/thành phố và phường/xã/đặc khu cho địa chỉ Việt Nam.
* Hỗ trợ trang thanh toán, My Account, giỏ hàng, cài đặt cửa hàng, hồ sơ khách hàng và đơn hàng trong trang quản trị.
* Lưu mã tỉnh/thành phố vào `state`, mã phường/xã vào `city` và ẩn mã bưu chính khi phù hợp.
* Xác thực tổ hợp tỉnh/thành phố và phường/xã trước khi lưu.
* Tải danh sách phường/xã theo nhu cầu và lưu bộ nhớ đệm phía trình duyệt.
* Chuẩn hóa số điện thoại Việt Nam cùng siêu dữ liệu E.164, loại số và nhà mạng.
* Thu thập yêu cầu hóa đơn GTGT khi được bật.
* Bổ sung VietQR cho WooCommerce Direct bank transfer với bộ chọn ngân hàng và BIN VietQR/NAPAS.
* Hiển thị và sao chép thông tin chuyển khoản trên trang đơn hàng, email và trang quản trị.
* Khung tích hợp đơn vị vận chuyển với mã vận đơn, trạng thái, phí, COD và thời gian đồng bộ.
* Công cụ di chuyển dữ liệu địa chỉ và vận chuyển từ plugin của Le Van Toan.
* Khai báo khả năng tương thích với WooCommerce High-Performance Order Storage (HPOS).

= Trường địa chỉ Việt Nam cho WooCommerce =

Plugin thay thế địa chỉ Việt Nam nhập tự do bằng các trường WooCommerce có cấu trúc:

* `state` lưu mã tỉnh/thành phố chính thức.
* `city` lưu mã phường/xã/đặc khu chính thức.
* `postcode` được ẩn trong quy trình thanh toán tại Việt Nam.
* `first_name` được dùng làm Họ và tên; `last_name` và trường công ty được ẩn ở trang thanh toán.
* `address_2` là dòng địa chỉ bổ sung không bắt buộc.

Do sử dụng các trường hiện có, plugin duy trì khả năng tương thích với đơn hàng, khách hàng, phí vận chuyển, thuế, dữ liệu xuất và các tích hợp WooCommerce.

= Dữ liệu địa chỉ =

Dữ liệu hành chính hai cấp phiên bản 2026-07 được đóng gói cùng plugin:

* 34 tỉnh/thành phố.
* 3.321 phường/xã/đặc khu.

Mã tỉnh/thành phố là chuỗi hai ký tự, chẳng hạn `01`; mã phường/xã/đặc khu là chuỗi năm ký tự, chẳng hạn `00070`. Dạng chuỗi giữ lại số 0 ở đầu và tránh nhầm lẫn giữa địa danh trùng tên.

= Hỗ trợ địa chỉ trong trang quản trị =

Danh sách tỉnh/thành phố và phường/xã được áp dụng cho địa chỉ cửa hàng, hồ sơ khách hàng và đơn hàng trong wp-admin. Địa chỉ đơn hàng được chuẩn hóa qua các hàm thiết lập của WooCommerce khi có thể.

= Yêu cầu hóa đơn GTGT =

Plugin thu thập yêu cầu hóa đơn GTGT khi thuế WooCommerce và tùy chọn hóa đơn Việt Nam được bật.

Khi khách hàng yêu cầu hóa đơn GTGT, trang thanh toán có thể thu thập:

* Tên pháp lý của công ty.
* Mã số thuế.
* Email nhận hóa đơn.
* Địa chỉ công ty.

Mã số thuế gồm 10 chữ số, có thể theo sau bởi dấu gạch nối và 3 chữ số.

Dữ liệu được lưu vào đơn hàng, hiển thị trong trang quản trị và email New Order gửi cho quản trị viên. Theo mặc định, dữ liệu không xuất hiện trong email khách hàng.

= Chuyển khoản ngân hàng bằng VietQR cho WooCommerce =

Plugin bổ sung VietQR cho WooCommerce Direct bank transfer (`bacs`) thay vì tạo cổng thanh toán riêng.

Chủ cửa hàng cấu hình tài khoản trong phần cài đặt hiện có. Bộ chọn ngân hàng lưu BIN VietQR/NAPAS cần thiết để tạo ảnh VietQR.

Với các đơn hàng chuyển khoản đủ điều kiện, plugin có thể hiển thị:

* Ảnh mã QR VietQR.
* Tên ngân hàng.
* Số tài khoản.
* Chủ tài khoản.
* Số tiền đơn hàng.
* Nội dung chuyển khoản.

Mẫu nội dung chuyển khoản hỗ trợ:

* `{order_id}`
* `{order_number}`
* `{site_name}`

Mẫu mặc định là `ORDER-{order_number}`.

Thông tin VietQR có thể xuất hiện trên trang xác nhận đơn hàng, My Account, email chuyển khoản và màn hình quản trị.

Plugin chỉ tạo thông tin QR; không xử lý hoặc xác nhận thanh toán, kết nối API giao dịch ngân hàng hay tự động đánh dấu đơn hàng đã thanh toán.

= Chuẩn hóa số điện thoại Việt Nam =

Plugin chấp nhận các định dạng phổ biến như `0987654321`, `098 765 4321`, `+84987654321`, `0084 987654321` và `84 987654321`.

Số hợp lệ được lưu theo định dạng quốc gia. Plugin cũng lưu:

* Định dạng E.164, chẳng hạn `+84987654321`.
* Loại điện thoại: `mobile` hoặc `landline`.
* Nhà mạng di động khi có thể nhận diện từ đầu số.

Tìm kiếm đơn hàng bao gồm siêu dữ liệu số điện thoại đã chuẩn hóa.

= Công cụ vận chuyển Việt Nam =

Khung vận chuyển dành cho trình kết nối nội bộ, plugin riêng hoặc tích hợp SaaS cung cấp:

* Một hộp thông tin vận chuyển Việt Nam trên màn hình quản trị đơn hàng WooCommerce.
* Đăng ký đơn vị vận chuyển thông qua bộ lọc `yoohw_vietnam_store_tools_shipping_providers`.
* Các thao tác quản trị để tạo vận đơn, đồng bộ vận đơn, in nhãn vận chuyển và hủy vận đơn khi đơn vị vận chuyển hỗ trợ.
* Siêu dữ liệu chuẩn hóa cho đơn vị vận chuyển, dịch vụ, nhãn, mã vận đơn, URL theo dõi, trạng thái, phí, bảo hiểm, COD và thời gian đồng bộ.
* Chọn trước đơn vị vận chuyển từ phương thức hoặc siêu dữ liệu vận chuyển tại trang thanh toán khi có.
* Loại bỏ siêu dữ liệu kỹ thuật `vck_*` của mức phí vận chuyển khỏi phần hiển thị dòng vận chuyển trong đơn hàng ở trang quản trị.

Plugin công khai không đi kèm API của GHTK, Viettel Post hoặc hãng vận chuyển khác. Tích hợp riêng cần được cung cấp bởi trình kết nối bổ sung.

= Công cụ di chuyển dữ liệu từ plugin của Le Van Toan =

WooCommerce Status Tools cung cấp công cụ di chuyển từ plugin thanh toán Việt Nam hoặc plugin GHTK của Le Van Toan:

* Quét địa chỉ đơn hàng cũ mà không thay đổi dữ liệu.
* Đồng bộ địa chỉ cũ có thể ánh xạ an toàn sang cấu trúc hiện tại.
* Sao lưu giá trị địa chỉ cũ trước khi lưu giá trị đã di chuyển.
* Đồng bộ dữ liệu theo dõi GHTK cũ sang siêu dữ liệu vận chuyển chuẩn hóa của plugin.
* Xử lý theo từng lô để giảm nguy cơ hết thời gian chờ.

Plugin cảnh báo khi phát hiện plugin cũ đang hoạt động và có thể xung đột với trường địa chỉ Việt Nam.

= Nguồn dữ liệu =

Dữ liệu hành chính đi kèm có siêu dữ liệu nguồn và dựa trên National Statistics Office of Viet Nam thông qua Vietnam Provinces API v2.

Dữ liệu ngân hàng từ VietQR bank list API hỗ trợ bộ chọn ngân hàng và BIN VietQR/NAPAS.

Dữ liệu chỉ chứa tên và mã định danh công khai như mã hành chính, tên đơn vị, tên ngân hàng và BIN. Đây là dữ liệu tham chiếu tĩnh, không phải điểm ảnh theo dõi hoặc ứng dụng khách API.

= Dịch vụ bên ngoài =

Các API được tham chiếu trong `data/SOURCES.md` chỉ là nguồn xây dựng dữ liệu tĩnh. Plugin không gọi các API nguồn này trong thời gian chạy.

Khi VietQR được bật cho WooCommerce Direct bank transfer, trình duyệt hoặc trình đọc email tải ảnh QR thanh toán từ dịch vụ ảnh VietQR.

Nhà cung cấp dịch vụ: VietQR.io by CASSO.

Trang web dịch vụ: https://vietqr.io/

Tài liệu Quick Link: https://vietqr.io/danh-sach-api/link-tao-ma-nhanh/

Điều khoản của CASSO: https://casso.vn/thoa-thuan-su-dung-phan-mem/

Chính sách quyền riêng tư của CASSO: https://casso.vn/chinh-sach-bao-mat-thong-tin/

Dữ liệu có thể gồm BIN ngân hàng nhận, số tài khoản, mẫu QR, số tiền, nội dung chuyển khoản và tên chủ tài khoản. Các giá trị chỉ được đưa vào URL ảnh khi VietQR được bật và tài khoản đã được cấu hình đầy đủ.

Plugin không sử dụng VietQR để xử lý hoặc xác nhận thanh toán, lưu dữ liệu thẻ của khách hàng hay chuyển tiền.

= Quyền riêng tư =

Plugin lưu dữ liệu thanh toán, địa chỉ, điện thoại, hóa đơn và vận chuyển trong cơ sở dữ liệu WordPress/WooCommerce của trang web.

Plugin không thêm tính năng theo dõi phân tích, điểm ảnh quảng cáo hoặc dịch vụ thu thập dữ liệu từ xa.

Nếu hiển thị VietQR cho khách hàng, chủ cửa hàng nên đề cập dịch vụ ảnh QR trong chính sách quyền riêng tư.

== Installation ==

1. Đảm bảo WooCommerce đã được cài đặt và kích hoạt.
2. Cài đặt Vietnam Store Toolkit for WooCommerce từ màn hình Plugin của WordPress hoặc tải các tệp plugin lên `/wp-content/plugins/yoohw-vietnam-store-tools`.
3. Kích hoạt plugin.
4. Kiểm tra lại khu vực bán hàng và cài đặt địa chỉ cửa hàng của WooCommerce.
5. Cấu hình WooCommerce Direct bank transfer nếu bạn muốn sử dụng VietQR.
6. Bật yêu cầu hóa đơn GTGT Việt Nam trong phần cài đặt thuế WooCommerce nếu cửa hàng cần các trường yêu cầu hóa đơn.
7. Nếu đang di chuyển từ plugin của Le Van Toan, hãy chạy công cụ quét trước khi đồng bộ dữ liệu cũ.

== Frequently Asked Questions ==

= Đây có phải là plugin thanh toán WooCommerce dành cho Việt Nam không? =

Có. Plugin điều chỉnh trang thanh toán WooCommerce, biểu mẫu địa chỉ trong My Account, công cụ tính phí vận chuyển trong giỏ hàng, hồ sơ khách hàng, phần cài đặt địa chỉ cửa hàng và tính năng chỉnh sửa địa chỉ đơn hàng trong trang quản trị để phù hợp với dữ liệu địa chỉ Việt Nam.

= Plugin lưu địa chỉ Việt Nam như thế nào? =

Plugin lưu mã tỉnh/thành phố vào trường `state` và mã phường/xã/đặc khu vào trường `city` của WooCommerce. Cách này duy trì khả năng tương thích với WooCommerce đồng thời tránh sự mơ hồ của địa chỉ chỉ được lưu dưới dạng văn bản.

= Plugin có hỗ trợ cơ cấu đơn vị hành chính Việt Nam năm 2026 không? =

Có. Dữ liệu địa chỉ đi kèm sử dụng cơ cấu hành chính hai cấp của Việt Nam phiên bản 2026-07 với 34 tỉnh/thành phố và 3.321 phường/xã/đặc khu.

= Plugin có thêm cổng thanh toán mới không? =

Không. VietQR được thêm vào WooCommerce Direct bank transfer. Khách hàng vẫn chọn phương thức chuyển khoản ngân hàng tích hợp sẵn.

= VietQR có tự động xác nhận thanh toán không? =

Không. Plugin hiển thị thông tin QR thanh toán. Plugin không kết nối với API giao dịch ngân hàng hoặc tự động đánh dấu đơn hàng là đã thanh toán.

= VietQR có thể bao gồm số tiền đơn hàng không? =

Có, khi tùy chọn thêm số tiền được bật và đơn vị tiền tệ của đơn hàng là VND. Đối với đơn hàng không dùng VND, mã QR vẫn có thể hiển thị thông tin chuyển khoản mà không nhúng số tiền.

= Tôi cấu hình tài khoản ngân hàng VietQR ở đâu? =

Đi tới phần cài đặt thanh toán WooCommerce và chỉnh sửa Direct bank transfer. Plugin bổ sung các tùy chọn ngân hàng Việt Nam và VietQR vào phần cài đặt tài khoản ngân hàng hiện có.

= Khách hàng có thể yêu cầu hóa đơn GTGT tại trang thanh toán không? =

Có. Hãy bật thuế WooCommerce, sau đó bật yêu cầu hóa đơn thuế Việt Nam trong phần cài đặt thuế WooCommerce. Khách hàng có thể yêu cầu hóa đơn và nhập thông tin công ty trong quá trình thanh toán.

= Định dạng mã số thuế Việt Nam nào được chấp nhận? =

Plugin chấp nhận 10 chữ số, có thể theo sau bởi dấu gạch nối và 3 chữ số, chẳng hạn `0312345678` hoặc `0312345678-001`.

= Tính năng chuẩn hóa số điện thoại có tự động thay đổi đơn hàng cũ không? =

Không. Dữ liệu số điện thoại của đơn hàng và khách hàng mới hoặc đã chỉnh sửa sẽ được chuẩn hóa khi lưu. Dữ liệu số điện thoại hiện có không được tự động cập nhật bổ sung.

= Plugin có hỗ trợ HPOS không? =

Có. Plugin khai báo khả năng tương thích với WooCommerce High-Performance Order Storage.

= Plugin công khai có bao gồm tích hợp GHTK hoặc Viettel Post không? =

Không. Plugin công khai bao gồm khung vận chuyển dùng chung và công cụ quản trị đơn hàng. Tích hợp dành riêng cho từng hãng vận chuyển cần được cung cấp bởi một trình kết nối riêng, nội bộ hoặc SaaS.

= Tôi có thể di chuyển dữ liệu từ plugin của Le Van Toan không? =

Có. Plugin bao gồm các công cụ WooCommerce Status Tools có thể quét và đồng bộ các dòng địa chỉ cũ có thể ánh xạ an toàn cùng siêu dữ liệu vận chuyển GHTK. Hãy chạy công cụ quét trước và xem lại báo cáo trước khi đồng bộ.

= Plugin có gọi API bên ngoài trong quá trình thanh toán không? =

Dữ liệu địa chỉ và danh sách ngân hàng được đóng gói sẵn. Dịch vụ bên ngoài duy nhất được sử dụng trong thời gian chạy là dịch vụ ảnh VietQR khi tính năng hiển thị VietQR được bật và ảnh QR được hiển thị.

= Plugin có bao gồm bản dịch tiếng Việt không? =

Có. Plugin bao gồm các tệp dịch tiếng Việt cho nhãn, thông báo xác thực, văn bản giao diện quản trị và tên đơn vị hành chính.

== Changelog ==

= 1.0.2 (5/7/2026) =

* Đã thêm mẫu email WooCommerce gửi khách hàng để cập nhật thông tin theo dõi vận chuyển tại Việt Nam.
* Đã thêm hộp kiểm trong hộp thông tin vận chuyển Việt Nam của đơn hàng ở trang quản trị để gửi email theo dõi khi lưu mã vận đơn thủ công.
* Đã cập nhật bảng chi tiết trong email theo dõi vận chuyển để phù hợp hơn với bố cục mẫu email mới của WooCommerce.
* Đã thêm bản dịch tiếng Việt cho email theo dõi vận chuyển mới và các điều khiển gửi thủ công.

Xem lịch sử thay đổi đầy đủ trong `changelog.txt`.

=== Vietnam Store Toolkit for WooCommerce ===
Contributors: yoohw, baonguyen0310
Tags: woocommerce, vietnam, vietqr, checkout blocks, shipping
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.9
WC tested up to: 10.9
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bộ công cụ WooCommerce Việt Nam cho địa chỉ hai cấp, Checkout Blocks, VietQR, hóa đơn GTGT, phí vận chuyển và tracking đơn hàng.

== Description ==

Vietnam Store Toolkit for WooCommerce bổ sung các công cụ cần thiết cho cửa hàng tại Việt Nam: địa chỉ tỉnh/thành phố và phường/xã, VietQR, hóa đơn GTGT, số điện thoại, phí vận chuyển, mã vận đơn và tra cứu đơn hàng.

= Tính năng chính =

* Địa chỉ hai cấp gồm 34 tỉnh/thành phố và 3.321 phường/xã/đặc khu.
* Danh sách Phường/Xã phụ thuộc Tỉnh/Thành trong Classic Checkout, Cart và Checkout Blocks.
* Yêu cầu hóa đơn GTGT và quy trình hóa đơn điện tử trung lập nhà cung cấp.
* VietQR cho WooCommerce Direct bank transfer.
* Chuẩn hóa và xác thực số điện thoại Việt Nam.
* Quy tắc phí vận chuyển theo địa chỉ, giỏ hàng, khối lượng, shipping class, miễn phí vận chuyển và COD.
* Mã vận đơn, timeline thủ công và trang tra cứu đơn hàng không cần API hãng vận chuyển.
* Bộ lọc, thao tác hàng loạt và xuất CSV đơn hàng, tương thích HPOS.
* Công cụ di chuyển địa chỉ và dữ liệu GHTK từ plugin của Le Van Toan.

= Có gì mới trong phiên bản 1.1.0 =

Phiên bản 1.1.0 bổ sung Checkout Blocks, Store API, quy tắc phí đến cấp phường/xã, hóa đơn điện tử, tracking không cần API và quản lý đơn hàng HPOS.

= Địa chỉ Việt Nam và Checkout Blocks =

Plugin lưu mã tỉnh/thành phố vào `state` và mã phường/xã vào `city` của WooCommerce, đồng thời ẩn mã bưu chính khi phù hợp. Tổ hợp địa chỉ được xác thực trước khi lưu và danh sách phường/xã chỉ tải khi cần.

Các trường được áp dụng cho checkout, My Account, giỏ hàng, địa chỉ cửa hàng, hồ sơ khách hàng và đơn hàng trong wp-admin. Trong Cart và Checkout Blocks, Phường/Xã là danh sách phụ thuộc Tỉnh/Thành và đồng bộ trực tiếp với Store API, kể cả trên trang block tùy chỉnh.

= Hóa đơn GTGT và VietQR =

Tính năng yêu cầu hóa đơn chỉ hoạt động khi “Kích hoạt thuế” và “Yêu cầu hóa đơn thuế” tại WooCommerce > Cài đặt > Thuế > Tùy chọn thuế cùng được bật. Classic Checkout và Checkout Block có thể thu thập tên công ty, mã số thuế, email nhận hóa đơn và địa chỉ công ty.

Quy trình hóa đơn điện tử lưu trạng thái, số/ký hiệu, ngày phát hành, URL tra cứu, nhà cung cấp, tệp PDF/XML và nhật ký thay đổi. Plugin không tự phát hành hóa đơn hoặc gọi API nhà cung cấp.

VietQR được thêm vào WooCommerce Direct bank transfer (`bacs`), không tạo cổng thanh toán mới. Ảnh QR và thông tin chuyển khoản có thể xuất hiện trên trang xác nhận đơn hàng, My Account, email và wp-admin. Plugin không xác nhận giao dịch hoặc tự đánh dấu đơn đã thanh toán.

= Phí vận chuyển và tracking đơn hàng =

Phương thức “Quy tắc phí vận chuyển” hoạt động trong WooCommerce Shipping Zones. Quy tắc được kiểm tra từ trên xuống dưới và có thể dựa trên tỉnh/thành phố, phường/xã, tổng giỏ hàng, khối lượng, shipping class, phí, ngưỡng miễn phí và COD. Trình chỉnh sửa hỗ trợ sắp xếp cùng nhập/xuất CSV UTF-8.

Hộp Vận chuyển cho phép nhập hãng, mã vận đơn, URL theo dõi, gửi email và ghi timeline thủ công. Mẫu URL tự tạo liên kết từ `{tracking_code}`; quản trị viên cũng có thể khai báo hãng tùy chỉnh.

Thêm block “Tra cứu đơn hàng” hoặc shortcode `[yoohw_order_tracking]` để khách tra cứu bằng mã đơn cùng email hoặc số điện thoại thanh toán. Kết quả không hiển thị địa chỉ, sản phẩm, tổng tiền hay thông tin liên hệ.

= Quản lý đơn hàng và HPOS =

Màn hình WooCommerce > Đơn hàng có cột Thông tin gọn cho hóa đơn và vận đơn, bộ lọc nâng cao, thao tác gửi lại email tracking, cập nhật hãng, xuất CSV và đánh dấu tiến độ bàn giao. Plugin dùng API đơn hàng WooCommerce, tương thích HPOS và chế độ lưu trữ cũ.

= Di chuyển và dữ liệu =

WooCommerce Status Tools có thể quét, sao lưu và đồng bộ theo lô địa chỉ cũ cùng dữ liệu GHTK từ plugin của Le Van Toan. Hãy chạy công cụ quét và xem báo cáo trước khi đồng bộ.

Dữ liệu hành chính phiên bản 2026-07 dựa trên National Statistics Office of Viet Nam thông qua Vietnam Provinces API v2. Danh sách ngân hàng và BIN dựa trên VietQR bank list API. Nguồn và quy trình cập nhật được ghi trong `data/SOURCES.md`.

= Dịch vụ bên ngoài và quyền riêng tư =

Các API nguồn chỉ được dùng để xây dựng dữ liệu tĩnh; plugin không gọi chúng trong thời gian chạy. Khi VietQR được bật, trình duyệt hoặc trình đọc email tải ảnh QR từ VietQR.io by CASSO. URL ảnh có thể chứa BIN, số tài khoản, mẫu QR, số tiền, nội dung chuyển khoản và tên chủ tài khoản.

* Dịch vụ: https://vietqr.io/
* Tài liệu: https://vietqr.io/danh-sach-api/link-tao-ma-nhan/
* Điều khoản: https://casso.vn/thoa-thuan-su-dung-phan-mem/
* Quyền riêng tư: https://casso.vn/chinh-sach-bao-mat-thong-tin/

Dữ liệu địa chỉ, điện thoại, hóa đơn và vận chuyển được lưu trong WordPress/WooCommerce của cửa hàng. Plugin không thêm phân tích, quảng cáo hoặc dịch vụ thu thập dữ liệu từ xa.

== Installation ==

1. Cài đặt và kích hoạt WooCommerce.
2. Cài và kích hoạt Vietnam Store Toolkit for WooCommerce.
3. Kiểm tra khu vực bán hàng và địa chỉ cửa hàng trong cài đặt WooCommerce.
4. Cấu hình Direct bank transfer nếu cần VietQR.
5. Nếu cần hóa đơn, bật thuế rồi bật “Yêu cầu hóa đơn thuế” trong Tùy chọn thuế.
6. Nếu cần phí theo địa chỉ, thêm “Quy tắc phí vận chuyển” vào Shipping Zone.
7. Nếu chuyển từ plugin của Le Van Toan, chạy công cụ quét trước khi đồng bộ.

== Frequently Asked Questions ==

= Plugin có hỗ trợ Cart và Checkout Blocks không? =

Có. Plugin hỗ trợ Tỉnh/Thành, Phường/Xã, hóa đơn GTGT, xác thực số điện thoại, VietQR và thông tin vận chuyển. WooCommerce 8.9 trở lên được yêu cầu.

= Plugin lưu địa chỉ Việt Nam như thế nào? =

Mã tỉnh/thành phố được lưu vào `state`; mã phường/xã/đặc khu được lưu vào `city` của WooCommerce.

= Khách hàng có thể yêu cầu hóa đơn GTGT không? =

Có, khi “Kích hoạt thuế” và “Yêu cầu hóa đơn thuế” cùng được bật.

= VietQR có tự xác nhận thanh toán không? =

Không. VietQR được thêm vào Direct bank transfer nhưng không kết nối giao dịch ngân hàng.

= Tôi cấu hình phí vận chuyển đến cấp phường/xã ở đâu? =

Đi tới WooCommerce > Cài đặt > Vận chuyển, mở một khu vực và thêm “Quy tắc phí vận chuyển”.

= Tôi tạo trang tra cứu đơn hàng như thế nào? =

Thêm block “Tra cứu đơn hàng” hoặc shortcode `[yoohw_order_tracking]` vào một trang.

= Plugin có tích hợp sẵn API hãng vận chuyển không? =

Không. Bản công khai cung cấp tracking không cần API và khung cho trình kết nối riêng.

= Plugin có hỗ trợ HPOS và tiếng Việt không? =

Có. Plugin tương thích HPOS và bao gồm bản dịch tiếng Việt cho giao diện, email cùng thông báo xác thực.

== Changelog ==

= 1.1.0 (17/7/2026) =

* Đã thêm Cart/Checkout Blocks cho địa chỉ Việt Nam, Store API, trang block tùy chỉnh và danh sách Phường/Xã phụ thuộc Tỉnh/Thành.
* Đã thêm yêu cầu hóa đơn GTGT trong Checkout Block; chuyển tùy chọn vào phần Thuế và chỉ kích hoạt tính năng khi WooCommerce bật thuế.
* Đã thêm quy trình hóa đơn điện tử trung lập nhà cung cấp với trạng thái, hồ sơ PDF/XML, bộ lọc, CSV và nhật ký.
* Đã thêm chuẩn hóa và xác thực số điện thoại Việt Nam cho Store API.
* Đã thêm Quy tắc phí vận chuyển theo Shipping Zones với điều kiện đến cấp phường/xã, giỏ hàng, khối lượng, shipping class, miễn phí vận chuyển, COD và CSV.
* Đã thêm tracking không cần API với mẫu URL, hãng tùy chỉnh, timeline, email, block và shortcode tra cứu.
* Đã thêm công cụ quản lý đơn hàng tương thích HPOS gồm cột Thông tin, bộ lọc, thao tác hàng loạt và xuất CSV.
* Đã hoàn thiện hiển thị VietQR và vận chuyển trên trang xác nhận đơn hàng, My Account và luồng Classic/Blocks.
* Đã rút gọn các nhãn giao diện; Việt hóa nút lưu/cập nhật mã vận đơn và đơn giản hóa hộp Vận chuyển khi chưa có trình kết nối.
* Đã thêm liên kết Cài đặt email cạnh tùy chọn gửi thông tin vận chuyển và xếp Tên/Số điện thoại trên cùng một hàng trong địa chỉ giao hàng.

Xem lịch sử thay đổi chi tiết trong `changelog.txt`.
